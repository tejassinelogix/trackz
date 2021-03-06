<?php

declare(strict_types=1);

namespace App\Controllers\Order;

use App\Library\Views;
use Delight\Auth\Auth;
use App\Models\Inventory\OrderSetting;
use App\Models\Order\PostageSetting;
use App\Models\Order\LabelSetting;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Account\Store;
use App\Models\Marketplace\Marketplace;
use Delight\Cookie\Cookie;
use Laminas\Diactoros\ServerRequest;
use App\Library\ValidateSanitize\ValidateSanitize;
use Laminas\Validator\File\FilesSize;
use Laminas\Validator\File\Extension;
use Exception;
use PDO;
use App\Library\Config;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as WriteXlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv as WriteCsv;
use Illuminate\Http\Request;
use Delight\Cookie\Session;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Stream;
use Laminas\Log\Formatter\Json;
use App\Library\Email;
use \Mpdf\Mpdf;
// use Resque;

class OrderController
{
    private $view;
    private $auth;
    private $db;
    private $storeid;
    /**
     * _construct - create object
     *
     * @param  $view PHP Plates template object
     * @param  $auth Delight Auth authorization object
     * @param  $db PDO object from service provider
     * @return no return
     */
    public function __construct(Views $view, Auth $auth, PDO $db)
    {
        $this->view = $view;
        $this->auth = $auth;
        $this->db   = $db;
        $store = (new Store($this->db))->find(Session::get('member_id'), 1);
        $this->storeid   = (isset($store[0]['Id']) && !empty($store[0]['Id'])) ? $store[0]['Id'] : 0;
        ini_set('memory_limit', '-1');
        ini_set("pcre.backtrack_limit", "1000000");
    }

    public function random_strings($length_of_string)
    {
        // String of all alphanumeric character 
        $str_result = '0123456789abcdefghijklmnopqrstuvwxyz';
        // Shufle the $str_result and returns substring 
        // of specified length 
        return substr(
            str_shuffle($str_result),
            0,
            $length_of_string
        );
    }
    public function browse()
    {
        $all_order = (new Order($this->db))->getAllBelongsTo();
        return $this->view->buildResponse('order/browse', ['all_order' => $all_order]);
    }


    /*
    * view - Load loadBatchMove view file
    * @param  - none
    * @return view
    */
    public function loadBatchMove()
    {
        return $this->view->buildResponse('order/batchmove', []);
    }

    /*
    * updateBatchMove - Update Batch Move
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names   
    * @return boolean 
    */
    public function updateBatchMove(ServerRequest $request)
    {
        try {

            $order_ids = array();
            $methodData = $request->getParsedBody();
            unset($methodData['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.        

            $map_data = $this->mapBatchMove($methodData);

            $order_ids = array_column($map_data, 'OrderId');

            $is_data = $this->insertOrUpdate($map_data);

            foreach ($order_ids as $order_id) {

                $mail_data = (new Order($this->db))->findByorder_id($order_id);

                $message['html']  = $this->view->make('emails/orderconfirm');
                $message['plain'] = $this->view->make('emails/plain/orderconfirm');
                $mailer = new Email();
                $mailer->sendEmail(
                    $mail_data['ShippingEmail'],
                    Config::get('company_name'),
                    _('Order Confirmation'),
                    $message,
                    ['OrderId' => $mail_data['OrderId'], 'BillingName' => $mail_data['BillingName'], 'Carrier' => $mail_data['Carrier'], 'Tracking' => $mail_data['Tracking']]
                );
            }

            if (isset($is_data) && !empty($is_data)) {
                $this->view->flash([
                    'alert' => 'Order Batch moved successfully..!',
                    'alert_type' => 'success'
                ]);
                return $this->view->buildResponse('order/batchmove', []);
            } else {
                throw new Exception("Order Id not found into our database...!", 301);
            }
        } catch (Exception $e) {

            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();
            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            return $this->view->buildResponse('order/batchmove', []);
        }
    }

    /*
         @author    :: Tejas
         @task_id   :: 
         @task_desc :: 
         @params    :: 
        */
    public function mapBatchMove($batch_data = array())
    {
        $order_ids = [];
        if ($batch_data['OrderId'] == trim($batch_data['OrderId']) && strpos($batch_data['OrderId'], ' ') !== false) {
            $order_ids = explode(" ", $batch_data['OrderId']);
        } else if ($batch_data['OrderId'] == trim($batch_data['OrderId']) && strpos($batch_data['OrderId'], ',') !== false) {
            $order_ids = explode(",", $batch_data['OrderId']);
        } else {
            $order_ids[] = $batch_data['OrderId'];
        }

        $map_order = [];
        $set_map_order = [];
        foreach ($order_ids as $order) {
            $map_order['OrderId'] = $order;
            $map_order['Status'] = $batch_data['UpdateStatusOrder'];
            $map_order['Carrier'] = $batch_data['ShippingCarrierOrder'];
            $set_map_order[] = $map_order;
        } // Loops Ends
        return $set_map_order;
    }

    /*
    * insertOrUpdate - find user id if exist
    *
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names
    * @return boolean
    */
    public function insertOrUpdate($data)
    {
        $result = false;
        foreach ($data as $key => $value) {
            $user_details = (new Order($this->db))->findByOrderID($value['OrderId'], Session::get('auth_user_id'));
            if (isset($user_details) && !empty($user_details)) { // update
                $data['Updated'] = date('Y-m-d H:i:s');
                $result = (new Order($this->db))->editOrder($user_details['Id'], $value);
            }
        } // Loops Ends
        return $result;
    }

    /*
    * view - Load loadConfirmationFile view file
    * @param  - none
    * @return view
    */
    public function loadConfirmationFile()
    {
        $all_order = (new Order($this->db))->getAllConfirmationFiles();
        return $this->view->buildResponse('order/confirmation_file', ['all_order' => $all_order]);
    }

    /*
    * view - Load loadExportOrder view file
    * @param  - none
    * @return view
    */
    public function loadExportOrder()
    {
        $all_order = (new Order($this->db))->getAllBelongsTo();
        return $this->view->buildResponse('order/export_order', ['all_order' => $all_order]);
        //return $this->view->buildResponse('order/defaults', []);
    }

    public function exportOrderData(ServerRequest $request)
    {
        try {
            $form = $request->getParsedBody();
            $export_type = $form['export_format'];
            $export_val   = $form['exportType'];
            $from_date    = $form['from_date'];
            $to_date         = $form['to_date'];

            if ($from_date == '') {
                $from_date = date('Y-m-d', strtotime("-7 days"));
            }

            if ($to_date == '') {
                $to_date = date('Y-m-d');
            }

            $orderStatus  = $form['orderStatus'];

            if ($export_val == 'new') {
                $order_data = (new Order($this->db))->orderstatusSearchByOrderData($export_val);
            }

            if ($export_val == 'range') {
                $formD  =  date("Y-m-d 00:00:00", strtotime($from_date));
                $ToD    =  date("Y-m-d 23:59:00", strtotime($to_date));
                $order_data = (new Order($this->db))->dateRangeSearchByOrderData($formD, $ToD);
            }

            if ($export_val == 'status') {
                $export_val = $orderStatus;
                $order_data = (new Order($this->db))->orderstatusSearchByOrderData($export_val);
            }

            if ($export_val == 'All') {
                $order_data = (new Order($this->db))->allorderSearchByOrderData_export_order();
            }

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setCellValue('A1', 'MarketPlaceId');
            $sheet->setCellValue('B1', 'OrderId');
            $sheet->setCellValue('C1', 'Status');
            $sheet->setCellValue('D1', 'Currency');
            $sheet->setCellValue('E1', 'PaymentStatus');
            $sheet->setCellValue('F1', 'PaymentMethod');
            $sheet->setCellValue('G1', 'BuyerNote');
            $sheet->setCellValue('H1', 'SellerNote');
            $sheet->setCellValue('I1', 'ShippingMethod');
            $sheet->setCellValue('J1', 'Tracking');
            $sheet->setCellValue('K1', 'Carrier');
            $sheet->setCellValue('L1', 'ShippingName');
            $sheet->setCellValue('M1', 'ShippingPhone');
            $sheet->setCellValue('N1', 'ShippingEmail');
            $sheet->setCellValue('O1', 'ShippingAddress1');
            $sheet->setCellValue('P1', 'ShippingAddress2');
            $sheet->setCellValue('Q1', 'ShippingAddress3');
            $sheet->setCellValue('R1', 'ShippingCity');
            $sheet->setCellValue('S1', 'ShippingState');
            $sheet->setCellValue('T1', 'ShippingZipCode');
            $sheet->setCellValue('U1', 'ShippingCountry');
            $sheet->setCellValue('V1', 'BillingName');
            $sheet->setCellValue('W1', 'BillingPhone');
            $sheet->setCellValue('X1', 'BillingEmail');
            $sheet->setCellValue('Y1', 'BillingAddress1');
            $sheet->setCellValue('Z1', 'BillingAddress2');
            $sheet->setCellValue('AA1', 'BillingAddress3');
            $sheet->setCellValue('AB1', 'BillingCity');
            $sheet->setCellValue('AC1', 'BillingState');
            $sheet->setCellValue('AD1', 'BillingZipCode');
            $sheet->setCellValue('AE1', 'BillingCountry');
            $rows = 2;
            foreach ($order_data as $orderd) {
                $sheet->setCellValue('A' . $rows, $orderd['MarketPlaceId']);
                $sheet->setCellValue('B' . $rows, $orderd['OrderId']);
                $sheet->setCellValue('C' . $rows, $orderd['Status']);
                $sheet->setCellValue('D' . $rows, $orderd['Currency']);
                $sheet->setCellValue('E' . $rows, $orderd['PaymentStatus']);
                $sheet->setCellValue('F' . $rows, $orderd['PaymentMethod']);
                $sheet->setCellValue('G' . $rows, $orderd['BuyerNote']);
                $sheet->setCellValue('H' . $rows, $orderd['SellerNote']);
                $sheet->setCellValue('I' . $rows, $orderd['ShippingMethod']);
                $sheet->setCellValue('J' . $rows, $orderd['Tracking']);
                $sheet->setCellValue('K' . $rows, $orderd['Carrier']);
                $sheet->setCellValue('L' . $rows, $orderd['ShippingName']);
                $sheet->setCellValue('M' . $rows, $orderd['ShippingPhone']);
                $sheet->setCellValue('N' . $rows, $orderd['ShippingEmail']);
                $sheet->setCellValue('O' . $rows, $orderd['ShippingAddress1']);
                $sheet->setCellValue('P' . $rows, $orderd['ShippingAddress2']);
                $sheet->setCellValue('Q' . $rows, $orderd['ShippingAddress3']);
                $sheet->setCellValue('R' . $rows, $orderd['ShippingCity']);
                $sheet->setCellValue('S' . $rows, $orderd['ShippingState']);
                $sheet->setCellValue('T' . $rows, $orderd['ShippingZipCode']);
                $sheet->setCellValue('U' . $rows, $orderd['ShippingCountry']);
                $sheet->setCellValue('V' . $rows, $orderd['BillingName']);
                $sheet->setCellValue('W' . $rows, $orderd['BillingPhone']);
                $sheet->setCellValue('X' . $rows, $orderd['BillingEmail']);
                $sheet->setCellValue('Y' . $rows, $orderd['BillingAddress1']);
                $sheet->setCellValue('Z' . $rows, $orderd['BillingAddress2']);
                $sheet->setCellValue('AA' . $rows, $orderd['BillingAddress3']);
                $sheet->setCellValue('AB' . $rows, $orderd['BillingCity']);
                $sheet->setCellValue('AC' . $rows, $orderd['BillingState']);
                $sheet->setCellValue('AD' . $rows, $orderd['BillingZipCode']);
                $sheet->setCellValue('AE' . $rows, $orderd['BillingCountry']);
                $rows++;
            }

            if ($export_type == 'xlsx' || $export_type == 'csv') {
                $this->view->flash([
                    'alert' => 'Order Data sucessfully export..!',
                    'alert_type' => 'success'
                ]);

                if ($export_type == 'xlsx') {
                    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, "Xlsx");
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    header('Content-Disposition: attachment; filename="orders.xlsx"');
                    $writer->save("php://output");
                    exit;
                } else if ($export_type == 'csv') {
                    $writer = new WriteCsv($spreadsheet);
                    header('Content-Type: application/csv');
                    header('Content-Disposition: attachment; filename="orders.csv"');
                    $writer->save("php://output");
                    exit;
                }
            } else {
                throw new Exception("Failed to update Settings. Please ensure all input is filled out correctly.", 301);
            }
        } catch (Exception $e) {


            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();

            $validated['alert'] = 'Please Select Xlsx or Csv File Format..!';
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            return $this->view->redirect('/order/export-order');
        }
    }

    /*
    * view - Load loadShippingOrder view file
    * @param  - none
    * @return view
    */
    public function loadShippingOrder()
    {
        return $this->view->buildResponse('order/defaults', []);
    }

    /*
    * view - Load loadOrderSetting view file
    * @param  - none
    * @return view
    */
    public function loadOrderSetting()
    {
        return $this->view->buildResponse('order/defaults', []);
    }

    /*
    * view - Load loadPostageSetting view file
    * @param  - none
    * @return view
    */
    public function loadPostageSetting()
    {
        $all_order = (new PostageSetting($this->db))->PostageSettingfindByUserId(Session::get('auth_user_id'));
        return $this->view->buildResponse('order/postage_setting', ['all_order' => $all_order]);
    }
    public function postageinsertOrUpdate($data)
    {
        $postage_setting = (new PostageSetting($this->db))->PostageSettingfindByUserId(Session::get('auth_user_id'));
        if (isset($postage_setting) && !empty($postage_setting)) { // update
            $data['Updated'] = date('Y-m-d H:i:s');
            $result = (new PostageSetting($this->db))->editPostageSettings($data);
        } else { // insert
            $data['Created'] = date('Y-m-d H:i:s');
            $result = (new PostageSetting($this->db))->addPostageSettings($data);
        }

        return $result;
    }
    /*
    * PostageAddupdateSettings - Add Update Postage Settings
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names   
    * @return boolean 
    */
    public function postageAddUpdateSettings(ServerRequest $request)
    {

        try {
            $methodData = $request->getParsedBody();
            unset($methodData['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.        

            $update_data['UserId'] = Session::get('auth_user_id');
            $update_data['OperatingSystem'] = (isset($methodData['OperatingSystem']) && !empty($methodData['OperatingSystem'])) ? $methodData['OperatingSystem'] : null;
            $update_data['MaxWeight'] = (isset($methodData['MaxWeight']) && !empty($methodData['MaxWeight'])) ? $methodData['MaxWeight'] : 0;
            $update_data['DeliveryConfirmation'] = (isset($methodData['DeliveryConfirmation']) && !empty($methodData['DeliveryConfirmation'])) ? $methodData['DeliveryConfirmation'] : null;
            $update_data['MinOrderTotalDelivery'] = (isset($methodData['MinOrderTotalDelivery']) && !empty($methodData['MinOrderTotalDelivery'])) ? $methodData['MinOrderTotalDelivery'] : 0.00;
            $update_data['SignatureConfirmation'] = (isset($methodData['SignatureConfirmation']) && !empty($methodData['SignatureConfirmation'])) ? $methodData['SignatureConfirmation'] : null;
            $update_data['ConsolidatorLabel'] = (isset($methodData['ConsolidatorLabel']) && !empty($methodData['ConsolidatorLabel'])) ? $methodData['ConsolidatorLabel'] : null;

            $update_data['IncludeInsurance'] = (isset($methodData['IncludeInsurance']) && !empty($methodData['IncludeInsurance'])) ? $methodData['IncludeInsurance'] : null;
            $update_data['MinOrderTotalInsurance'] = (isset($methodData['MinOrderTotalInsurance']) && !empty($methodData['MinOrderTotalInsurance'])) ? $methodData['MinOrderTotalInsurance'] : 0.00;
            $update_data['RoundDownPartial'] = (isset($methodData['RoundDownPartial']) && !empty($methodData['RoundDownPartial'])) ? $methodData['RoundDownPartial'] : null;

            $update_data['EstimatePostage'] = (isset($methodData['EstimatePostage']) && !empty($methodData['EstimatePostage'])) ? $methodData['EstimatePostage'] : null;
            $update_data['MaxPostageBatch'] = (isset($methodData['MaxPostageBatch']) && !empty($methodData['MaxPostageBatch'])) ? $methodData['MaxPostageBatch'] : null;
            $update_data['CustomsSigner'] = (isset($methodData['CustomsSigner']) && !empty($methodData['CustomsSigner'])) ? $methodData['CustomsSigner'] : null;
            $update_data['DefaultWeight'] = (isset($methodData['DefaultWeight']) && !empty($methodData['DefaultWeight'])) ? $methodData['DefaultWeight'] : null;
            $update_data['FlatRatePriority'] = (isset($methodData['FlatRatePriority'])) ? $methodData['FlatRatePriority'] : 0;
            $update_data['GlobalWeight'] = (isset($methodData['GlobalWeight'])) ? $methodData['GlobalWeight'] : 0;

            $is_data = $this->postageinsertOrUpdate($update_data);
            if (isset($is_data) && !empty($is_data)) {
                $this->view->flash([
                    'alert' => 'Postage settings updated successfully..!',
                    'alert_type' => 'success'
                ]);
                $all_order = (new PostageSetting($this->db))->PostageSettingfindByUserId(Session::get('auth_user_id'));
                return $this->view->buildResponse('order/postage_setting', ['all_order' => $all_order]);
            } else {
                throw new Exception("Failed to update Settings. Please ensure all input is filled out correctly.", 301);
            }
        } catch (Exception $e) {
            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();

            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);

            $all_order = (new PostageSetting($this->db))->PostageSettingfindByUserId(Session::get('auth_user_id'));
            return $this->view->buildResponse('order/postage_setting', ['all_order' => $all_order]);
        }
    }



    /*
    * view - Load loadLabelSetting view file
    * @param  - none
    * @return view
    */
    public function loadLabelSetting()
    {
        $all_order = (new LabelSetting($this->db))->LabelSettingfindByUserId(Session::get('auth_user_id'));
        return $this->view->buildResponse('order/label_setting', ['all_order' => $all_order]);
    }
    public function labelinsertOrUpdate($data)
    {
        $label_setting = (new LabelSetting($this->db))->LabelSettingfindByUserId(Session::get('auth_user_id'));

        if (!empty($label_setting)) { // update

            $data['Updated'] = date('Y-m-d H:i:s');

            $result = (new LabelSetting($this->db))->editLabelSettings($data);
        } else { // insert
            $data['Created'] = date('Y-m-d H:i:s');
            $result = (new LabelSetting($this->db))->addLabelSettings($data);
        }

        return $result;
    }
    /*
    * LabelAddupdateSettings - Add Update Label Settings
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names   
    * @return boolean 
    */
    public function LabelAddUpdateSettings(ServerRequest $request)
    {

        try {
            $methodData = $request->getParsedBody();
            unset($methodData['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.        

            $update_data['UserId'] = Session::get('auth_user_id');

            $update_data['SkipPDFView'] = (isset($methodData['SkipPDFView']) && !empty($methodData['SkipPDFView'])) ? $methodData['SkipPDFView'] : 0;
            $update_data['DefaultAction'] = (isset($methodData['DefaultAction']) && !empty($methodData['DefaultAction'])) ? $methodData['DefaultAction'] : null;
            $update_data['SortOrders'] = (isset($methodData['SortOrders']) && !empty($methodData['SortOrders'])) ? $methodData['SortOrders'] : null;

            $update_data['SplitOrders'] = (isset($methodData['SplitOrders']) && !empty($methodData['SplitOrders'])) ? $methodData['SplitOrders'] : 0;
            $update_data['AddBarcode'] = (isset($methodData['AddBarcode']) && !empty($methodData['AddBarcode'])) ? $methodData['AddBarcode'] : 0;

            $update_data['BarcodeType'] = (isset($methodData['BarcodeType']) && !empty($methodData['BarcodeType'])) ? $methodData['BarcodeType'] : null;
            $update_data['SortPickList'] = (isset($methodData['SortPickList']) && !empty($methodData['SortPickList'])) ? $methodData['SortPickList'] : null;

            $update_data['DefaultTemplate'] = (isset($methodData['DefaultTemplate']) && !empty($methodData['DefaultTemplate'])) ? $methodData['DefaultTemplate'] : null;
            $update_data['HeaderImageURL'] = (isset($methodData['HeaderImageURL']) && !empty($methodData['HeaderImageURL'])) ? $methodData['HeaderImageURL'] : null;
            $update_data['FooterImageURL'] = (isset($methodData['FooterImageURL']) && !empty($methodData['FooterImageURL'])) ? $methodData['FooterImageURL'] : null;
            $update_data['PackingSlipHeader'] = (isset($methodData['PackingSlipHeader']) && !empty($methodData['PackingSlipHeader'])) ? $methodData['PackingSlipHeader'] : null;
            $update_data['PackingSlipFooter'] = (isset($methodData['PackingSlipFooter']) && !empty($methodData['PackingSlipFooter'])) ? $methodData['PackingSlipFooter'] : null;
            $update_data['PackingSlipFrom'] = (isset($methodData['PackingSlipFrom']) && !empty($methodData['PackingSlipFrom'])) ? $methodData['PackingSlipFrom'] : null;

            $update_data['IncludeOrderBarcodes'] = (isset($methodData['IncludeOrderBarcodes']) && !empty($methodData['IncludeOrderBarcodes'])) ? 1 : 0;
            $update_data['IncludeItemBarcodes'] = (isset($methodData['IncludeItemBarcodes']) && !empty($methodData['IncludeItemBarcodes'])) ? 1 : 0;
            $update_data['CentreHeaderText'] = (isset($methodData['CentreHeaderText']) && !empty($methodData['CentreHeaderText'])) ? 1 : 0;
            $update_data['HideEmail'] = (isset($methodData['HideEmail']) && !empty($methodData['HideEmail'])) ? 1 : 0;
            $update_data['HidePhone'] = (isset($methodData['HidePhone']) && !empty($methodData['HidePhone'])) ? 1 : 0;
            $update_data['IncludeGSTExAus1'] = (isset($methodData['IncludeGSTExAus1']) && !empty($methodData['IncludeGSTExAus1'])) ? 1 : 0;
            $update_data['CentreFooter'] = (isset($methodData['CentreFooter']) && !empty($methodData['CentreFooter'])) ? 1 : 0;
            $update_data['ShowItemPrice'] = (isset($methodData['ShowItemPrice']) && !empty($methodData['ShowItemPrice'])) ? 1 : 0;
            $update_data['IncludeMarketplaceOrder'] = (isset($methodData['IncludeMarketplaceOrder']) && !empty($methodData['IncludeMarketplaceOrder'])) ? 1 : 0;
            $update_data['IncludePageNumbers'] = (isset($methodData['IncludePageNumbers']) && !empty($methodData['IncludePageNumbers'])) ? 1 : 0;

            $update_data['ColumnsPerPage'] = (isset($methodData['ColumnsPerPage']) && !empty($methodData['ColumnsPerPage'])) ? $methodData['ColumnsPerPage'] : 0;
            $update_data['RowsPerPage'] = (isset($methodData['RowsPerPage']) && !empty($methodData['RowsPerPage'])) ? $methodData['RowsPerPage'] : 0;
            $update_data['FontSize'] = (isset($methodData['FontSize']) && !empty($methodData['FontSize'])) ? $methodData['FontSize'] : 0;
            $update_data['HideLabelBoundaries'] = (isset($methodData['HideLabelBoundaries']) && !empty($methodData['HideLabelBoundaries'])) ? $methodData['HideLabelBoundaries'] : 0;
            $update_data['IncludeGSTExAus2'] = (isset($methodData['IncludeGSTExAus2']) && !empty($methodData['IncludeGSTExAus2'])) ? 1 : 0;

            $update_data['LabelWidth'] = (isset($methodData['LabelWidth']) && !empty($methodData['LabelWidth'])) ? $methodData['LabelWidth'] : 0.00;

            $update_data['LabelWidthIn'] = (isset($methodData['LabelWidthIn']) && !empty($methodData['LabelWidthIn'])) ? $methodData['LabelWidthIn'] : null;
            $update_data['LabelHeight'] =  (isset($methodData['LabelHeight']) && !empty($methodData['LabelHeight'])) ? $methodData['LabelHeight'] : 0.00;
            $update_data['LabelHeightIn'] = (isset($methodData['LabelHeightIn']) && !empty($methodData['LabelHeightIn'])) ? $methodData['LabelHeightIn'] : null;
            $update_data['PageMargins'] = (isset($methodData['PageMargins']) && !empty($methodData['PageMargins'])) ? $methodData['PageMargins'] : 0.0;
            $update_data['PageMarginsIn'] = (isset($methodData['PageMarginsIn']) && !empty($methodData['PageMarginsIn'])) ? $methodData['PageMarginsIn'] : null;
            $update_data['LabelMargins'] = (isset($methodData['LabelMargins']) && !empty($methodData['LabelMargins'])) ? $methodData['LabelMargins'] : 0.00;
            $update_data['LabelMarginsIn'] = (isset($methodData['LabelMarginsIn']) && !empty($methodData['LabelMarginsIn'])) ? $methodData['LabelMarginsIn'] : null;


            $is_data = $this->labelinsertOrUpdate($update_data);

            if (isset($is_data) && !empty($is_data)) {
                $this->view->flash([
                    'alert' => 'Label settings updated successfully..!',
                    'alert_type' => 'success'
                ]);
                $all_order = (new LabelSetting($this->db))->LabelSettingfindByUserId(Session::get('auth_user_id'));
                return $this->view->buildResponse('order/label_setting', ['all_order' => $all_order]);
            } else {
                throw new Exception("Failed to update Settings. Please ensure all input is filled out correctly.", 301);
            }
        } catch (Exception $e) {

            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();

            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            /*$user_details = (new PostageSetting($this->db))->PostageSettingfindByUserId(Session::get('auth_user_id'));
            return $this->view->buildResponse('order/postage_setting', ['all_settings' => $user_details]);*/

            $all_order = (new LabelSetting($this->db))->LabelSettingfindByUserId(Session::get('auth_user_id'));
            return $this->view->buildResponse('order/label_setting', ['all_order' => $all_order]);
        }
    }



    public function orderinsertOrUpdate($data)
    {
        $order_setting = (new OrderSetting($this->db))->OrderSettingfindByUserId(Session::get('auth_user_id'));
        if (isset($order_setting) && !empty($order_setting)) { // update
            $data['Updated'] = date('Y-m-d H:i:s');
            $result = (new OrderSetting($this->db))->editOrderSettings($data);
        } else { // insert
            $data['Created'] = date('Y-m-d H:i:s');
            $result = (new OrderSetting($this->db))->addInventorySettings($data);
        }

        return $result;
    }



    /*
    * Order - Order setting
    *
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names
    * @return boolean
    */
    public function orderSettingsBrowse()
    {
        $order_details = (new OrderSetting($this->db))->OrderSettingfindByUserId(Session::get('auth_user_id'));
        return $this->view->buildResponse('inventory/settings/order', ['order_details' => $order_details]);
    }


    /*
    * OrderupdateSettings - Update Order Settings
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names   
    * @return boolean 
    */
    public function orderUpdateSettings(ServerRequest $request)
    {
        try {
            $methodData = $request->getParsedBody();
            unset($methodData['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.        

            $update_data['UserId'] = Session::get('auth_user_id');
            $update_data['ConfirmEmail'] = (isset($methodData['ConfirmEmail']) && !empty($methodData['ConfirmEmail'])) ? $methodData['ConfirmEmail'] : null;
            $update_data['CancelEmail'] = (isset($methodData['CancelEmail']) && !empty($methodData['CancelEmail'])) ? $methodData['CancelEmail'] : null;
            $update_data['DeferEmail'] = (isset($methodData['DeferEmail']) && !empty($methodData['DeferEmail'])) ? $methodData['DeferEmail'] : null;
            $update_data['DontSendCopy'] = (isset($methodData['DontSendCopy']) && !empty($methodData['DontSendCopy'])) ? 1 : 0;
            $update_data['NoAdditionalOrder'] = json_encode([
                'AdditionalOrder' => (isset($methodData['NoAdditionalOrder']) && !empty($methodData['NoAdditionalOrder'])) ? $methodData['NoAdditionalOrder'] : null,
                'AdditionalOrderData' => [
                    'work1' => (isset($methodData['NoAdditionalOrder1']) && !empty($methodData['NoAdditionalOrder1'])) ? $methodData['NoAdditionalOrder1'] : null,
                    'work2' => (isset($methodData['NoAdditionalOrder2']) && !empty($methodData['NoAdditionalOrder2'])) ? $methodData['NoAdditionalOrder2'] : null,
                    'work3' => (isset($methodData['NoAdditionalOrder3']) && !empty($methodData['NoAdditionalOrder3'])) ? $methodData['NoAdditionalOrder3'] : null,
                    'work4' => (isset($methodData['NoAdditionalOrder4']) && !empty($methodData['NoAdditionalOrder4'])) ? $methodData['NoAdditionalOrder4'] : null,
                    'work5' => (isset($methodData['NoAdditionalOrder5']) && !empty($methodData['NoAdditionalOrder5'])) ? $methodData['NoAdditionalOrder5'] : null,
                    'work6' => (isset($methodData['NoAdditionalOrder6']) && !empty($methodData['NoAdditionalOrder6'])) ? $methodData['NoAdditionalOrder6'] : null,
                    'work7' => (isset($methodData['NoAdditionalOrder7']) && !empty($methodData['NoAdditionalOrder7'])) ? $methodData['NoAdditionalOrder7'] : null,
                    'work8' => (isset($methodData['NoAdditionalOrder8']) && !empty($methodData['NoAdditionalOrder8'])) ? $methodData['NoAdditionalOrder8'] : null,
                    'work9' => (isset($methodData['NoAdditionalOrder9']) && !empty($methodData['NoAdditionalOrder9'])) ? $methodData['NoAdditionalOrder9'] : null,
                    'work10' => (isset($methodData['NoAdditionalOrder10']) && !empty($methodData['NoAdditionalOrder10'])) ? $methodData['NoAdditionalOrder10'] : null,
                    'work11' => (isset($methodData['NoAdditionalOrder11']) && !empty($methodData['NoAdditionalOrder11'])) ? $methodData['NoAdditionalOrder11'] : null,
                    'work12' => (isset($methodData['NoAdditionalOrder12']) && !empty($methodData['NoAdditionalOrder12'])) ? $methodData['NoAdditionalOrder12'] : null,
                    'work13' => (isset($methodData['NoAdditionalOrder13']) && !empty($methodData['NoAdditionalOrder13'])) ? $methodData['NoAdditionalOrder13'] : null,
                    'work14' => (isset($methodData['NoAdditionalOrder14']) && !empty($methodData['NoAdditionalOrder14'])) ? $methodData['NoAdditionalOrder14'] : null,
                    'work15' => (isset($methodData['NoAdditionalOrder15']) && !empty($methodData['NoAdditionalOrder15'])) ? $methodData['NoAdditionalOrder15'] : null,
                    'work16' => (isset($methodData['NoAdditionalOrder16']) && !empty($methodData['NoAdditionalOrder16'])) ? $methodData['NoAdditionalOrder16'] : null,
                    'work17' => (isset($methodData['NoAdditionalOrder17']) && !empty($methodData['NoAdditionalOrder17'])) ? $methodData['NoAdditionalOrder17'] : null,
                    'work18' => (isset($methodData['NoAdditionalOrder18']) && !empty($methodData['NoAdditionalOrder18'])) ? $methodData['NoAdditionalOrder18'] : null,
                    'work19' => (isset($methodData['NoAdditionalOrder19']) && !empty($methodData['NoAdditionalOrder19'])) ? $methodData['NoAdditionalOrder19'] : null,
                    'work20' => (isset($methodData['NoAdditionalOrder20']) && !empty($methodData['NoAdditionalOrder20'])) ? $methodData['NoAdditionalOrder20'] : null
                ],
            ]);

            $is_data = $this->orderinsertOrUpdate($update_data);

            if (isset($is_data) && !empty($is_data)) {
                $this->view->flash([
                    'alert' => 'Order settings updated successfully..!',
                    'alert_type' => 'success'
                ]);
                $order_details = (new OrderSetting($this->db))->OrderSettingfindByUserId(Session::get('auth_user_id'));
                return $this->view->buildResponse('inventory/settings/order', ['order_details' => $order_details]);
            } else {
                throw new Exception("Failed to update Settings. Please ensure all input is filled out correctly.", 301);
            }
        } catch (Exception $e) {

            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();

            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            $user_details = (new OrderSetting($this->db))->OrderSettingfindByUserId(Session::get('auth_user_id'));
            return $this->view->buildResponse('inventory/settings/order', ['all_settings' => $user_details]);
        }
    }


    /*
    * view - Load addLoadView view file
    * @param  - none
    * @return view
    */
    public function addLoadView()
    {
        $products = (new Product($this->db))->getActiveUserAll(Session::get('auth_user_id'), [1, 0]);
        $market_places = (new Marketplace($this->db))->findByUserId(Session::get('auth_user_id'), 1);
        return $this->view->buildResponse('order/add', ['market_places' => $market_places, 'products' => $products]);
    }


    /*
    * addOrder - 
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names
    * @return boolean
    */
    public function addOrder(ServerRequest $request)
    {
        $form = $request->getParsedBody();
        unset($form['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.      
        try {
            // Sanitize and Validate
            $validate = new ValidateSanitize();
            $form = $validate->sanitize($form); // only trims & sanitizes strings (other filters available)
            $validate->validation_rules(array(
                'MarketPlaceOrder'    => 'required',
                'PaymentMethod'       => 'required',
                'BuyerNote'       => 'required',
                'StoreProductId' => 'required',
                'SellerNote'       => 'required',
                'ShippingMethod'       => 'required',
                'Tracking'       => 'required',
                'ShippingName'       => 'required',
                'ShippingPhone'       => 'required',
                'ShippingEmail'       => 'required',
                'ShippingAddress1'       => 'required',
                'ShippingAddress2'       => 'required',
                'ShippingAddress3'       => 'required',
                'ShippingCity'       => 'required',
                'ShippingState'       => 'required',
                'ShippingZipCode'       => 'required',
                'ShippingCountry'       => 'required',
                'BillingName'       => 'required',
                'BillingPhone'       => 'required',
                'BillingEmail'       => 'required',
                'BillingAddress1'       => 'required',
                'BillingAddress2'       => 'required',
                'BillingAddress3'       => 'required',
                'BillingCity'       => 'required',
                'BillingState'       => 'required',
                'BillingZipCode'       => 'required',
                'BillingCountry'       => 'required',
            ));

            $validated = $validate->run($form);
            // use validated as it is filtered and validated        
            if ($validated === false) {
                throw new Exception("Please enter required fields...!", 301);
            }

            // check product availablity in stock
            $is_avail = $this->checkProductQty($form['StoreProductId']);

            if (!$is_avail['status'])
                throw new Exception("Sorry, Product is not available in stock...!", 301);

            $insert_data = $this->PrepareInsertData($form);
            $order_obj = new Order($this->db);
            $all_order = $order_obj->addOrder($insert_data);
            // Update Product Qty : Decrease product qty
            $prodUpdate['Qty'] = $is_avail['qty'] - 1;
            $prod_obj = new Product($this->db);
            $all_prod = $prod_obj->updateProdInventory($form['StoreProductId'], $prodUpdate);

            // Email Start
            $message['html']  = $this->view->make('emails/orderconfirm');
            $message['plain'] = $this->view->make('emails/plain/orderconfirm');
            $mailer = new Email();
            $mailer->sendEmail(
                $form['ShippingEmail'],
                Config::get('company_name'),
                _('Order Confirmation'),
                $message,
                ['OrderId' => $form['MarketPlaceOrder'], 'Carrier' => $form['CarrierOrder'], 'BillingName' => $form['BillingName'], 'Tracking' => $form['Tracking']]
            );
            // Email End
            if (isset($all_order) && !empty($all_order)) {
                $this->view->flash([
                    'alert' => _('Order added successfully..!'),
                    'alert_type' => 'success'
                ]);
                return $this->view->redirect('/order/browse');
            } else {
                throw new Exception("Sorry we encountered an issue.  Please try again.", 301);
            }
        } catch (Exception $e) {

            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();

            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);

            $products = (new Product($this->db))->getActiveUserAll(Session::get('auth_user_id'), [1, 0]);
            $market_places = (new Marketplace($this->db))->findByUserId(Session::get('auth_user_id'), 1);
            return $this->view->buildResponse('order/add', ['market_places' => $market_places, 'products' => $products, 'form' => $form]);
        }
    }


    /*
         @author    :: Tejas
         @task_id   :: check product availablity
         @task_desc :: In stock product details
         @params    :: 
        */
    public function checkProductQty($product_id = "")
    {
        $res['status'] = false;
        $res['qty'] = 0;
        if (empty($product_id))
            return $res;

        $products = (new Product($this->db))->findById($product_id);
        if (isset($products) && !empty($products) && $products['Qty'] > 0) {
            $res['status'] = true;
            $res['qty'] = $products['Qty'];
            return $res;
        } else {
            return $res;
        }
    }

    /*
    * PrepareInsertData - Assign Value to new array and prepare insert data    
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names
    * @return array
    */
    private function PrepareInsertData($form = array())
    {
        $form_data = array();
        $form_data['MarketPlaceId'] = (isset($form['MarketName']) && !empty($form['MarketName'])) ? $form['MarketName'] : null;
        $form_data['OrderId'] = (isset($form['MarketPlaceOrder']) && !empty($form['MarketPlaceOrder'])) ? $form['MarketPlaceOrder'] : null;
        $form_data['StoreProductId'] = (isset($form['StoreProductId']) && !empty($form['StoreProductId'])) ? $form['StoreProductId'] : null;
        $form_data['Status'] = (isset($form['OrderStatus']) && !empty($form['OrderStatus'])) ? $form['OrderStatus'] : null;
        $form_data['Currency'] = (isset($form['Currency']) && !empty($form['Currency'])) ? $form['Currency'] : 0;
        $form_data['PaymentStatus'] = (isset($form['PaymentStatus']) && !empty($form['PaymentStatus'])) ? $form['PaymentStatus'] : null;
        $form_data['PaymentMethod'] = (isset($form['PaymentMethod']) && !empty($form['PaymentMethod'])) ? $form['PaymentMethod'] : null;
        $form_data['BuyerNote'] = (isset($form['BuyerNote']) && !empty($form['BuyerNote'])) ? $form['BuyerNote'] : null;
        $form_data['SellerNote'] = (isset($form['SellerNote']) && !empty($form['SellerNote'])) ? $form['SellerNote'] : null;
        $form_data['ShippingMethod'] = (isset($form['ShippingMethod']) && !empty($form['ShippingMethod'])) ? $form['ShippingMethod'] : null;
        $form_data['Tracking'] = (isset($form['Tracking']) && !empty($form['Tracking'])) ? $form['Tracking'] : null;
        $form_data['Carrier'] = (isset($form['CarrierOrder']) && !empty($form['CarrierOrder'])) ? $form['CarrierOrder'] : null;
        // Shipping
        $form_data['ShippingName'] = (isset($form['ShippingName']) && !empty($form['ShippingName'])) ? $form['ShippingName'] : null;
        $form_data['ShippingPhone'] = (isset($form['ShippingPhone']) && !empty($form['ShippingPhone'])) ? $form['ShippingPhone'] : null;
        $form_data['ShippingEmail'] = (isset($form['ShippingEmail']) && !empty($form['ShippingEmail'])) ? $form['ShippingEmail'] : null;
        $form_data['ShippingAddress1'] = (isset($form['ShippingAddress1']) && !empty($form['ShippingAddress1'])) ? $form['ShippingAddress1'] : null;
        $form_data['ShippingAddress2'] = (isset($form['ShippingAddress2']) && !empty($form['ShippingAddress2'])) ? $form['ShippingAddress2'] : null;
        $form_data['ShippingAddress3'] = (isset($form['ShippingAddress3']) && !empty($form['ShippingAddress3'])) ? $form['ShippingAddress3'] : null;
        $form_data['ShippingCity'] = (isset($form['ShippingCity']) && !empty($form['ShippingCity'])) ? $form['ShippingCity'] : null;
        $form_data['ShippingState'] = (isset($form['ShippingState']) && !empty($form['ShippingState'])) ? $form['ShippingState'] : null;
        $form_data['ShippingZipCode'] = (isset($form['ShippingZipCode']) && !empty($form['ShippingZipCode'])) ? $form['ShippingZipCode'] : null;
        $form_data['ShippingCountry'] = (isset($form['ShippingCountry']) && !empty($form['ShippingCountry'])) ? $form['ShippingCountry'] : null;
        // Billing
        $form_data['BillingName'] = (isset($form['BillingName']) && !empty($form['BillingName'])) ? $form['BillingName'] : null;
        $form_data['BillingPhone'] = (isset($form['BillingPhone']) && !empty($form['BillingPhone'])) ? $form['BillingPhone'] : null;
        $form_data['BillingEmail'] = (isset($form['BillingEmail']) && !empty($form['BillingEmail'])) ? $form['BillingEmail'] : null;
        $form_data['BillingAddress1'] = (isset($form['BillingAddress1']) && !empty($form['BillingAddress1'])) ? $form['BillingAddress1'] : null;
        $form_data['BillingAddress2'] = (isset($form['BillingAddress2']) && !empty($form['BillingAddress2'])) ? $form['BillingAddress2'] : null;
        $form_data['BillingAddress3'] = (isset($form['BillingAddress3']) && !empty($form['BillingAddress3'])) ? $form['BillingAddress3'] : null;
        $form_data['BillingCity'] = (isset($form['BillingCity']) && !empty($form['BillingCity'])) ? $form['BillingCity'] : null;
        $form_data['BillingState'] = (isset($form['BillingState']) && !empty($form['BillingState'])) ? $form['BillingState'] : null;
        $form_data['BillingZipCode'] = (isset($form['BillingZipCode']) && !empty($form['BillingZipCode'])) ? $form['BillingZipCode'] : null;
        $form_data['BillingCountry'] = (isset($form['BillingCountry']) && !empty($form['BillingCountry'])) ? $form['BillingCountry'] : null;

        $form_data['UserId'] = Session::get('auth_user_id');
        $form_data['StoreId'] = $this->storeid;
        $form_data['Created'] = date('Y-m-d H:I:s');
        return $form_data;
    }

    /*
    * deleteOrderData - Delete Category Data By Id    
    * @param  $form  - Id    
    * @return boolean
    */
    public function deleteOrderData(ServerRequest $request)
    {
        $form = $request->getParsedBody();
        $result_data = (new Order($this->db))->delete($form['Id']);
        if (isset($result_data) && !empty($result_data)) {
            $validated['alert'] = 'Order record deleted successfully..!';
            $validated['alert_type'] = 'success';
            $this->view->flash($validated);

            $res['status'] = true;
            $res['data'] = array();
            $res['message'] = 'Records deleted successfully..!';
            echo json_encode($res);
            exit;
        } else {
            $validated['alert'] = 'Sorry, Order records not deleted..! Please try again.';
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);

            $res['status'] = false;
            $res['data'] = array();
            $res['message'] = 'Records not Deleted..!';
            echo json_encode($res);
            exit;
        }
    }

    /*
    * view - Load editOrder view file
    * @param  - none
    * @return view
    */
    public function editOrder(ServerRequest $request, $Id = [])
    {
        $form = (new Order($this->db))->findById($Id['Id']);
        $products = (new Product($this->db))->getActiveUserAll(Session::get('auth_user_id'), [1, 0]);
        $market_places = (new Marketplace($this->db))->findByUserId(Session::get('auth_user_id'), 1);
        if (is_array($form) && !empty($form)) {
            return $this->view->buildResponse('order/edit', ['market_places' => $market_places, 'form' => $form, 'products' => $products, 'hidden_prod' => $form['StoreProductId']]);
        } else {
            $this->browse->flash([
                'alert' => 'Failed to fetch Order details. Please try again.',
                'alert_type' => 'danger'
            ]);
            return $this->view->buildResponse('order/edit', ['market_places' => $market_places, 'form' => $form, 'products' => $products, 'hidden_prod' => $form['StoreProductId']]);
        }
    }

    /*
    * updateOrder - Update Category data
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names   
    * @return boolean 
    */
    public function updateOrder(ServerRequest $request, $Id = [])
    {
        try {
            $methodData = $request->getParsedBody();
            unset($methodData['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.        

            // Sanitize and Validate
            $validate = new ValidateSanitize();
            $form = $validate->sanitize($methodData);

            // start mail

            $message['html']  = $this->view->make('emails/orderconfirm');
            $message['plain'] = $this->view->make('emails/plain/orderconfirm');
            $mailer = new Email();
            $mailer->sendEmail(
                $form['ShippingEmail'],
                Config::get('company_name'),
                _('Order Confirmation'),
                $message,
                ['OrderId' => $form['MarketPlaceOrder'], 'BillingName' => $form['BillingName'], 'Carrier' => $form['CarrierOrder'], 'Tracking' => $form['Tracking']]
            );

            //End mail

            // only trims & sanitizes strings (other filters available)
            $validate->validation_rules(array(
                'MarketPlaceOrder'    => 'required',
                'PaymentMethod'       => 'required',
                'StoreProductId' => 'required',
                'BuyerNote'       => 'required',
                'SellerNote'       => 'required',
                'ShippingMethod'       => 'required',
                'Tracking'       => 'required',
                'ShippingName'       => 'required',
                'ShippingPhone'       => 'required',
                'ShippingEmail'       => 'required',
                'ShippingAddress1'       => 'required',
                'ShippingAddress2'       => 'required',
                'ShippingAddress3'       => 'required',
                'ShippingCity'       => 'required',
                'ShippingState'       => 'required',
                'ShippingZipCode'       => 'required',
                'ShippingCountry'       => 'required',
                'BillingName'       => 'required',
                'BillingPhone'       => 'required',
                'BillingEmail'       => 'required',
                'BillingAddress1'       => 'required',
                'BillingAddress2'       => 'required',
                'BillingAddress3'       => 'required',
                'BillingCity'       => 'required',
                'BillingState'       => 'required',
                'BillingZipCode'       => 'required',
                'BillingCountry'       => 'required',
            ));

            $validated = $validate->run($form);
            // use validated as it is filtered and validated        
            if ($validated === false) {
                throw new Exception("Please enter required fields...!", 301);
            }

            $update_prod = false;
            if (!empty($methodData['hidden_prod']) && $methodData['hidden_prod'] != $methodData['StoreProductId']) {
                $update_prod = true;
                // check product availablity in stock
                $is_avail = $this->checkProductQty($form['StoreProductId']);
                if (isset($is_avail['status']) && $is_avail['status'] == false)
                    throw new Exception("Sorry, Product is not available in stock...!", 301);
            }


            $update_data = $this->PrepareUpdateData($methodData);
            $Id = (isset($form['Id']) && !empty($form['Id'])) ? $form['Id'] : null;
            $is_updated = (new Order($this->db))->editOrder($Id, $update_data);

            if ($update_prod == true) {
                // Update Product Qty : Decrease product qty
                $prodUpdate['Qty'] = $is_avail['qty'] - 1;
                $prod_obj = new Product($this->db);
                $all_prod = $prod_obj->updateProdInventory($form['StoreProductId'], $prodUpdate);
            }

            if (isset($is_updated) && !empty($is_updated)) {
                $this->view->flash([
                    'alert' => 'Order record updated successfully..!',
                    'alert_type' => 'success'
                ]);
                $all_order = (new Order($this->db))->getAllBelongsTo();
                return $this->view->buildResponse('order/browse', ['all_order' => $all_order]);
            } else {
                throw new Exception("Failed to update Order. Please ensure all input is filled out correctly.", 301);
            }
        } catch (Exception $e) {

            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();
            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            $products = (new Product($this->db))->getActiveUserAll(Session::get('auth_user_id'), [1, 0]);
            $market_places = (new Marketplace($this->db))->findByUserId(Session::get('auth_user_id'), 1);
            return $this->view->buildResponse('order/edit', ['market_places' => $market_places, 'form' => $methodData, 'products' => $products, 'hidden_prod' => $methodData['hidden_prod']]);
        }
    }

    /*
    * PrepareUpdateData - Assign Value to new array and prepare update data    
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names
    * @return array
    */
    private function PrepareUpdateData($form = array())
    {
        $form_data = array();
        $form_data['MarketPlaceId'] = (isset($form['MarketName']) && !empty($form['MarketName'])) ? $form['MarketName'] : null;
        $form_data['OrderId'] = (isset($form['MarketPlaceOrder']) && !empty($form['MarketPlaceOrder'])) ? $form['MarketPlaceOrder'] : null;
        $form_data['Status'] = (isset($form['OrderStatus']) && !empty($form['OrderStatus'])) ? $form['OrderStatus'] : null;
        $form_data['Currency'] = (isset($form['Currency']) && !empty($form['Currency'])) ? $form['Currency'] : 0;
        $form_data['PaymentStatus'] = (isset($form['PaymentStatus']) && !empty($form['PaymentStatus'])) ? $form['PaymentStatus'] : null;
        $form_data['PaymentMethod'] = (isset($form['PaymentMethod']) && !empty($form['PaymentMethod'])) ? $form['PaymentMethod'] : null;
        $form_data['BuyerNote'] = (isset($form['BuyerNote']) && !empty($form['BuyerNote'])) ? $form['BuyerNote'] : null;
        $form_data['SellerNote'] = (isset($form['SellerNote']) && !empty($form['SellerNote'])) ? $form['SellerNote'] : null;
        $form_data['ShippingMethod'] = (isset($form['ShippingMethod']) && !empty($form['ShippingMethod'])) ? $form['ShippingMethod'] : null;
        $form_data['Tracking'] = (isset($form['Tracking']) && !empty($form['Tracking'])) ? $form['Tracking'] : null;
        $form_data['Carrier'] = (isset($form['CarrierOrder']) && !empty($form['CarrierOrder'])) ? $form['CarrierOrder'] : null;
        // Shipping
        $form_data['ShippingName'] = (isset($form['ShippingName']) && !empty($form['ShippingName'])) ? $form['ShippingName'] : null;
        $form_data['ShippingPhone'] = (isset($form['ShippingPhone']) && !empty($form['ShippingPhone'])) ? $form['ShippingPhone'] : null;
        $form_data['ShippingEmail'] = (isset($form['ShippingEmail']) && !empty($form['ShippingEmail'])) ? $form['ShippingEmail'] : null;
        $form_data['ShippingAddress1'] = (isset($form['ShippingAddress1']) && !empty($form['ShippingAddress1'])) ? $form['ShippingAddress1'] : null;
        $form_data['ShippingAddress2'] = (isset($form['ShippingAddress2']) && !empty($form['ShippingAddress2'])) ? $form['ShippingAddress2'] : null;
        $form_data['ShippingAddress3'] = (isset($form['ShippingAddress3']) && !empty($form['ShippingAddress3'])) ? $form['ShippingAddress3'] : null;
        $form_data['ShippingCity'] = (isset($form['ShippingCity']) && !empty($form['ShippingCity'])) ? $form['ShippingCity'] : null;
        $form_data['ShippingState'] = (isset($form['ShippingState']) && !empty($form['ShippingState'])) ? $form['ShippingState'] : null;
        $form_data['ShippingZipCode'] = (isset($form['ShippingZipCode']) && !empty($form['ShippingZipCode'])) ? $form['ShippingZipCode'] : null;
        $form_data['ShippingCountry'] = (isset($form['ShippingCountry']) && !empty($form['ShippingCountry'])) ? $form['ShippingCountry'] : null;
        // Billing
        $form_data['BillingName'] = (isset($form['BillingName']) && !empty($form['BillingName'])) ? $form['BillingName'] : null;
        $form_data['BillingPhone'] = (isset($form['BillingPhone']) && !empty($form['BillingPhone'])) ? $form['BillingPhone'] : null;
        $form_data['BillingEmail'] = (isset($form['BillingEmail']) && !empty($form['BillingEmail'])) ? $form['BillingEmail'] : null;
        $form_data['BillingAddress1'] = (isset($form['BillingAddress1']) && !empty($form['BillingAddress1'])) ? $form['BillingAddress1'] : null;
        $form_data['BillingAddress2'] = (isset($form['BillingAddress2']) && !empty($form['BillingAddress2'])) ? $form['BillingAddress2'] : null;
        $form_data['BillingAddress3'] = (isset($form['BillingAddress3']) && !empty($form['BillingAddress3'])) ? $form['BillingAddress3'] : null;
        $form_data['BillingCity'] = (isset($form['BillingCity']) && !empty($form['BillingCity'])) ? $form['BillingCity'] : null;
        $form_data['BillingState'] = (isset($form['BillingState']) && !empty($form['BillingState'])) ? $form['BillingState'] : null;
        $form_data['BillingZipCode'] = (isset($form['BillingZipCode']) && !empty($form['BillingZipCode'])) ? $form['BillingZipCode'] : null;
        $form_data['BillingCountry'] = (isset($form['BillingCountry']) && !empty($form['BillingCountry'])) ? $form['BillingCountry'] : null;
        $form_data['StoreId'] = $this->storeid;
        $form_data['Updated'] = date('Y-m-d H:i:s');
        return $form_data;
    }

    /*
    * searchOrder - Update Batch Move
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names   
    * @return boolean 
    */
    public function searchOrder(ServerRequest $request)
    {
        try {
            $methodData = $request->getParsedBody();
            unset($methodData['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.        

            $result = (new Product($this->db))->searchProductFilter($methodData);
            if (isset($result) && !empty($result)) {
                $this->view->flash([
                    'alert' => 'Order result retrieved successfully..!',
                    'alert_type' => 'success'
                ]);
                return $this->view->buildResponse('order/browse', ['all_order' => $result]);
            } else {
                throw new Exception("Search result not found...!", 301);
            }
        } catch (Exception $e) {

            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();
            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';

            $this->view->flash($validated);
            return $this->view->buildResponse('order/browse', []);
        }
    }

    /*
    * updateOrderStatus - Update Batch Move
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names   
    * @return boolean 
    */
    public function updateOrderStatus(ServerRequest $request)
    {
        try {
            $methodData = $request->getParsedBody();
            unset($methodData['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.        

            if (!isset($methodData['ids']) || empty($methodData['ids']))
                throw new Exception("Please select checkbox", 1);

            $map_data = $this->_mapOrderStatusUpdate($methodData);

            if (isset($map_data) && !empty($map_data)) {
                $this->view->flash([
                    'alert' => 'Order Status updated successfully..!',
                    'alert_type' => 'success'
                ]);
                $res['status'] = true;
                $res['data'] = [];
                $res['message'] = 'Order Status updated successfully..!';
                die(json_encode($res));
                //return $this->view->buildResponse('order/browse', []);
            } else {
                throw new Exception("Order Status not updated...!", 301);
            }
        } catch (Exception $e) {

            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();
            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = 'Order Status not updated..!';
            die(json_encode($res));
            // return $this->view->buildResponse('order/browse', []);
        }
    }


    /*
    @author    :: Tejas
    @task_id   :: 
    @task_desc :: 
    @params    :: 
    */
    public function _mapOrderStatusUpdate($status_data = [])
    {
        foreach ($status_data['ids'] as $key_data => $value) {

            $update_result = (new Order($this->db))->editOrder($value, ['Status' => $status_data['status']]);


            $mail_data = (new Order($this->db))->findById($value);

            $all_data = (new OrderSetting($this->db))->getAllRecord();

            if (!empty($all_data)) {
                if ($status_data['status'] == 'shipped') {
                    $string = str_replace('%ORDERID%', $mail_data['OrderId'], $all_data[0]['ConfirmEmail']);
                    $string = str_replace('%BILLNAME%', $mail_data['BillingName'], $string);
                    $string = str_replace('%SOURCE%', $mail_data['MarketPlaceId'], $string);
                    $string = str_replace('%MARKETID%', $mail_data['MarketPlaceId'], $string);
                    $string = str_replace('%SHIPTO%', $mail_data['ShippingAddress1'] . ',' . $mail_data['ShippingAddress2'] . ',' . $mail_data['ShippingAddress3'], $string);
                    $string = str_replace('%TITLESNOTES%', $mail_data['BuyerNote'], $string);
                    $string = str_replace('%METHOD%', $mail_data['ShippingMethod'], $string);
                    $string = str_replace('%TRACKING%', $mail_data['Tracking'], $string);
                    $string = str_replace('%MYNAME%', $mail_data['ShippingName'], $string);
                    $string = str_replace('%MYEMAIL%', $mail_data['ShippingEmail'], $string);

                    //echo "<pre>";print_r($string);exit;

                    $mailer = new Email();
                    $mailer->sendEmaildynamic(
                        $mail_data['ShippingEmail'],
                        Config::get('company_name'),
                        _('Order Confirmation'),
                        $string
                    );
                } elseif ($status_data['status'] == 'deferred') {
                    $string = str_replace('%ORDERID%', $mail_data['OrderId'], $all_data[0]['DeferEmail']);
                    $string = str_replace('%BILLNAME%', $mail_data['BillingName'], $string);
                    $string = str_replace('%SOURCE%', $mail_data['MarketPlaceId'], $string);
                    $string = str_replace('%MARKETID%', $mail_data['MarketPlaceId'], $string);
                    $string = str_replace('%SHIPTO%', $mail_data['ShippingAddress1'] . ',' . $mail_data['ShippingAddress2'] . ',' . $mail_data['ShippingAddress3'], $string);
                    $string = str_replace('%TITLESNOTES%', $mail_data['BuyerNote'], $string);
                    $string = str_replace('%METHOD%', $mail_data['ShippingMethod'], $string);
                    $string = str_replace('%TRACKING%', $mail_data['Tracking'], $string);
                    $string = str_replace('%MYNAME%', $mail_data['ShippingName'], $string);
                    $string = str_replace('%MYEMAIL%', $mail_data['ShippingEmail'], $string);

                    //echo "<pre>";print_r($string);exit;

                    $mailer = new Email();
                    $mailer->sendEmaildynamic(
                        $mail_data['ShippingEmail'],
                        Config::get('company_name'),
                        _('Order deferred'),
                        $string
                    );
                } elseif ($status_data['status'] == 'cancelled') {
                    $string = str_replace('%ORDERID%', $mail_data['OrderId'], $all_data[0]['CancelEmail']);
                    $string = str_replace('%BILLNAME%', $mail_data['BillingName'], $string);
                    $string = str_replace('%SOURCE%', $mail_data['MarketPlaceId'], $string);
                    $string = str_replace('%MARKETID%', $mail_data['MarketPlaceId'], $string);
                    $string = str_replace('%SHIPTO%', $mail_data['ShippingAddress1'] . ',' . $mail_data['ShippingAddress2'] . ',' . $mail_data['ShippingAddress3'], $string);
                    $string = str_replace('%TITLESNOTES%', $mail_data['BuyerNote'], $string);
                    $string = str_replace('%METHOD%', $mail_data['ShippingMethod'], $string);
                    $string = str_replace('%TRACKING%', $mail_data['Tracking'], $string);
                    $string = str_replace('%MYNAME%', $mail_data['ShippingName'], $string);
                    $string = str_replace('%MYEMAIL%', $mail_data['ShippingEmail'], $string);

                    $mailer = new Email();
                    $mailer->sendEmaildynamic(
                        $mail_data['ShippingEmail'],
                        Config::get('company_name'),
                        _('Order Cancelled'),
                        $string
                    );
                } else {
                    $message['html']  = $this->view->make('emails/orderconfirm');
                    $message['plain'] = $this->view->make('emails/plain/orderconfirm');
                    $mailer = new Email();
                    $mailer->sendEmail(
                        $mail_data['ShippingEmail'],
                        Config::get('company_name'),
                        _('Order Confirmation'),
                        $message,
                        ['OrderId' => $mail_data['OrderId'], 'BillingName' => $mail_data['BillingName'], 'Carrier' => $mail_data['Carrier'], 'Tracking' => $mail_data['Tracking']]
                    );
                    return true;
                }
            } else {
                $message['html']  = $this->view->make('emails/orderconfirm');
                $message['plain'] = $this->view->make('emails/plain/orderconfirm');
                $mailer = new Email();
                $mailer->sendEmail(
                    $mail_data['ShippingEmail'],
                    Config::get('company_name'),
                    _('Order Confirmation'),
                    $message,
                    ['OrderId' => $mail_data['OrderId'], 'BillingName' => $mail_data['BillingName'], 'Carrier' => $mail_data['Carrier'], 'Tracking' => $mail_data['Tracking']]
                );
            }
        } // Loops Ends
        return true;
    }


    /*
     @author    :: Tejas
     @task_id   :: 
     @task_desc :: 
     @params    :: 
    */
    public function updateOrderChange(ServerRequest $request)
    {
        try {
            $methodData = $request->getParsedBody();
            unset($methodData['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.        

            $map_data = (new Order($this->db))->getStatusOrders($methodData['OrderStatus']);
            if (isset($map_data) && !empty($map_data)) {
                $this->view->flash([
                    'alert' => 'Order Status get successfully..!',
                    'alert_type' => 'success'
                ]);
                return $this->view->buildResponse('order/browse', ['all_order' => $map_data]);
            } else {
                throw new Exception("Order result not get...!", 301);
            }
        } catch (Exception $e) {

            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();
            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            return $this->view->buildResponse('order/browse', []);
        }
    }
    /*
    * pickOrder - Update Batch Move
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names   
    * @return boolean 
    */
    public function pickOrder()
    {
        return $this->view->buildResponse('order/pick', []);
    }
    /*
    * packingOrder - load packinglist view
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names   
    * @return boolean 
    */
    public function packingOrder()
    {
        $UserId = Session::get('auth_user_id');
        $pdf_parameter = (new Order($this->db))->pdf_sorting_parameter($UserId);
        return $this->view->buildResponse('order/packingslip', ['pdf_parameter' => $pdf_parameter]);
    }

    /*
    * mailingOrder - load mailinglist view
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names   
    * @return boolean 
    */
    public function mailingOrder()
    {
        $UserId = Session::get('auth_user_id');
        $pdf_parameter = (new Order($this->db))->pdf_sorting_parameter($UserId);
        return $this->view->buildResponse('order/mailinglabel', ['pdf_parameter' => $pdf_parameter]);
    }


    /*
   @author    :: Tejas
   @task_id   :: 
   @task_desc :: load html view and generate pdf and download
   @params    :: 
   @return    :: pdf download
  */
    public function pdfGenerateMailingLoad(ServerRequest $request)
    {
        $form = $request->getParsedBody();
        unset($form['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.      
        try {
            // require(dirname(dirname(dirname(dirname(__FILE__)))) . '\resources\views\default\order\pdf_mailinglabel.php')
            // Sanitize and Validate
            $validate = new ValidateSanitize();
            $form = $validate->sanitize($form); // only trims & sanitizes strings (other filters available)
            $validate->validation_rules(array(
                'status'    => 'required'
            ));

            $validated = $validate->run($form);
            // use validated as it is filtered and validated        
            if ($validated === false) {
                throw new Exception("Please select required fields...!", 301);
            }
            $pdf_data = (new Order($this->db))->allorderSearchByOrderData($form);
            $mailing_html = $this->loadMailinghtml($pdf_data);

            $mpdf = new Mpdf();
            $mpdf->WriteHTML($mailing_html);
            $mpdf->Output('assets/order/mailing/mailing.pdf', 'F');
            die(json_encode(['status' => true, 'message' => 'File downloaded successfully..!', 'data' => null, 'filename' => '/mailing.pdf']));
        } catch (Exception $e) {

            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();
            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            die(json_encode($res));
            // return $this->view->buildResponse('order/mailing', []);
        }
    }

    /*
     @author    :: Tejas
     @task_id   :: 
     @task_desc :: 
     @params    :: 
     @return    :: 
    */
    public function loadMailinghtml($pdf_data)
    {
        $all_order = (new LabelSetting($this->db))->LabelSettingfindByUserId(Session::get('auth_user_id'));

        $font_size = '12px';
        if (isset($all_order['FontSize']) && !empty($all_order['FontSize']) && $all_order['FontSize'] == 'xx-small') {
            $font_size = '6px';
        } else if (isset($all_order['FontSize']) && !empty($all_order['FontSize']) && $all_order['FontSize'] == 'x-small') {
            $font_size = '8px';
        } else if (isset($all_order['FontSize']) && !empty($all_order['FontSize']) && $all_order['FontSize'] == 'small') {
            $font_size = '10px';
        } else if (isset($all_order['FontSize']) && !empty($all_order['FontSize']) && $all_order['FontSize'] == 'medium') {
            $font_size = '12px';
        } else if (isset($all_order['FontSize']) && !empty($all_order['FontSize']) && $all_order['FontSize'] == 'large') {
            $font_size = '14px';
        } else if (isset($all_order['FontSize']) && !empty($all_order['FontSize']) && $all_order['FontSize'] == 'x-large') {
            $font_size = '16px';
        } else if (isset($all_order['FontSize']) && !empty($all_order['FontSize']) && $all_order['FontSize'] == 'xx-large') {
            $font_size = '18px';
        }
        $html = "";
        $html .= "";
        $html .= "<!DOCTYPE html>";
        $html .= "<html>";
        $html .= "<head>";
        $html .= "<title></title>";
        $html .= "</head>";
        $html .= "<style>table {
            border:none;
            border-collapse: collapse;
        }        
        table td {
            border-left: 1px solid #000;
            border-right: 1px solid #000;
        }        
        table td:first-child {
            border-left: none;
        }        
        table td:last-child {
            border-right: none;
        }</style>";
        $html .= "<body>";
        $html .= "<table class='table' id='custom_tbl' border='2' width='100%' style='border-collapse: collapse;font-size:" . $font_size . "'>";
        $html .= "<thead>";
        $html .= "<th style='border:1px solid black;'>";
        $html .= "</th>";
        $html .= "<th style='border:1px solid black;'>";
        $html .= "</th>";
        $html .= "<th style='border:1px solid black;'>";
        $html .= "</th>";
        $html .= "<tbody>";
        if (isset($pdf_data) && !empty($pdf_data)) {
            foreach (array_chunk($pdf_data, 3) as $row_key => $row_val) {
                $html .= "<tr style='border:1px solid black;'>";
                foreach ($row_val as $val_pdf) {
                    $html .= "<td scope='col'>";
                    $html .= $val_pdf['ShippingName'] . '<br>';
                    $html .= $val_pdf['ShippingAddress1'] . '<br>';
                    $html .= $val_pdf['ShippingAddress2'] . '<br>';
                    $html .= $val_pdf['ShippingAddress3'] . '<br>';
                    $html .= $val_pdf['ShippingCity'] . "," . $val_pdf['ShippingState'] . '<br>';
                    $html .= $val_pdf['ShippingCountry'];
                    $html .= "</td>";
                }
                $html .= "</tr>";
            }
        } else {
            $html .= "<tr>No Records Found</tr>";
        }
        $html .= "</tbody>";
        $html .= "</table>";
        $html .= "</body>";
        $html .= "</html>";
        return $html;
    }

    /*
   @author    :: Tejas
   @task_id   :: 
   @task_desc :: load html view and generate pdf and download
   @params    :: 
   @return    :: pdf download
  */
    public function pdfGeneratePackingLoad(ServerRequest $request)
    {
        $form = $request->getParsedBody();
        unset($form['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.      
        try {
            $pdf_data = (new Order($this->db))->getPackingOrders($form);
            // $view = $this->view->buildResponse('order/pdf_pick', ['pdf_data' => $pdf_data]);
            if (isset($form['OrderSort']) && $form['OrderSort'] == 'full') {
                $packing_html = $this->loadPackinghtml($pdf_data);
                $stylesheet = file_get_contents(getcwd() . "/assets/stylesheets/pdf_packing.css"); // external css
                $mpdf = new Mpdf(['UTF-8', 'A4', '', '', 20, 15, 48, 25, 10, 10]);
            } else if (isset($form['OrderSort']) && $form['OrderSort'] == 'small') {
                $stylesheet = file_get_contents(getcwd() . "/assets/stylesheets/pdf_packing.css"); // external css
                $packing_html = $this->loadPackingSmallHtml($pdf_data);
                $mpdf = new Mpdf();
            } else if (isset($form['OrderSort']) && $form['OrderSort'] == 'self-sticklabel') {
                $stylesheet = file_get_contents(getcwd() . "/assets/stylesheets/pdf_packingselfstick.css"); // external css
                $packing_html = $this->loadPackingSelfStickHtml($pdf_data);
                $mpdf = new Mpdf();
            } else if (isset($form['OrderSort']) && $form['OrderSort'] == '92mmfold') {
                $packing_html = $this->loadPacking92FoldHtml($pdf_data);
                $stylesheet = file_get_contents(getcwd() . "/assets/stylesheets/pdf_packing_92fold.css"); // external css
                $mpdf = new Mpdf(['orientation' => 'L']);
            } else if (isset($form['OrderSort']) && $form['OrderSort'] == 'mailingslip') {
                $packing_html = $this->loadPackingMailingHtml($pdf_data);
                $stylesheet = file_get_contents(getcwd() . "/assets/stylesheets/pdf_packing_mailing.css"); // external css
                $mpdf = new Mpdf();
            } else if (isset($form['OrderSort']) && $form['OrderSort'] == 'integratedlabel') {
                $packing_html = $this->loadPackingIntegrateLabel($pdf_data);
                $stylesheet = file_get_contents(getcwd() . "/assets/stylesheets/pdf_packing_integratelabel.css"); // external css
                $mpdf = new Mpdf();
            } else {
                throw new Exception("No PDF layout selected..!", 1);
            }
            $mpdf->use_kwt = true;
            $mpdf->WriteHTML($stylesheet, 1);
            $mpdf->WriteHTML($packing_html);
            $mpdf->Output('assets/order/packing/packing.pdf', 'F');
            die(json_encode(['status' => true, 'message' => 'File downloaded successfully..!', 'data' => null, 'filename' => '/packing.pdf']));
        } catch (Exception $e) {
            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();

            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            die(json_encode($res));
        }
    }

    /*
     @author    :: Tejas
     @task_id   :: 
     @task_desc :: 
     @params    :: 
     @return    :: 
    */
    public function loadPackinghtml($pdf_data)
    {
        $all_order = (new LabelSetting($this->db))->LabelSettingfindByUserId(Session::get('auth_user_id'));
        
        $image = '';
        if (isset($all_order) && !empty($all_order)) {
            $image = 'data:image/png;base64,' . base64_encode(file_get_contents(getcwd() . '/assets/images/' . $all_order['BarcodeType'] . '.png'));
            
             $PackingSlipHeader = $all_order['PackingSlipHeader'];

             $PackingSlipFooter = $all_order['PackingSlipFooter'];
        }


        // $img_barcode = \App\Library\Config::get('company_url') . '/assets/images/code39.PNG';
        $html = "";
        $html .= "";
        $html .= "<!DOCTYPE html>";
        $html .= "<html>";
        $html .= "<head>";
        $html .= "<title></title>";
        $html .= "</head>";
        $html .= "<body>";
        if (isset($pdf_data) && !empty($pdf_data)) {
            end($pdf_data);
            $key = key($pdf_data);
            foreach ($pdf_data as $key_data => $val_data) {
                $class_break = (isset($key_data) && $key_data == $key) ? '' : 'page-break';
                $html .= "<table class='table " . $class_break . "' cellpadding='8' id='custom_tbl' border='2' width='100%' style=''>";
                $html .= "<thead>";
                $html .= "</thead>";
                $html .= "<tbody>";
                $html .= "<tr>";
                $html .= "<td colspan='3'>";
               if (isset($all_order['HeaderImageURL']) && !empty($all_order['HeaderImageURL'])) {
                    $headerimageurl = $all_order['HeaderImageURL'];
                    $allow = ['gif', 'jpg', 'png'];  // allowed extensions
                    $url_info = pathinfo($headerimageurl);
                    if(in_array(isset($url_info['extension']), $allow) && !empty($url_info['extension'])) {
                        $html .= "<img src='" . $headerimageurl . "' height='250px;'>&nbsp;&nbsp;&nbsp;";
                    
                    }  
                    else
                    {
                        $html .= "";
                    }
                   }
                 $html .= "</td>";
                 $html .= "<td colspan='3'>";
                 if($all_order['CentreHeaderText'] == '1')
                 {
                    $html .= "<p style='font-size:20px;text-align:center;'><b>" . $PackingSlipHeader . "</b></p>";
                 }
                 else
                 {
                    $html .= "<p style='font-size:20px;'><b>" . $PackingSlipHeader . "</b></p>";
                 }
                 
                 $html .= "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='4'>";
                $html .= "<div class='main_packing'>";
                
                
                $html .= "<div class='main_packing_left'>";
                
                 $html .= "<br>";
                $html .= "<h2 style='margin-bottom:50px;'>Order: " . $val_data['OrderId'] . "</h2>";
                $html .= "<br>";
                $html .= "<p style='font-size:18px;'>(" . $val_data['MarketplaceName'] . "Order: #" . $val_data['OrderId'] . ")</p>";
                $html .= "<p style='font-size:20px;'><b>Date Ordered :</b>&nbsp;" . $val_data['OrderDate'] . "</p>";
                $html .= "<p style='font-size:20px;'><b>Shipping Method: </b>&nbsp;" . $val_data['ShippingMethod'] . "</p>";
                $html .= "</div>";
                $html .= "<div class='main_packing_right'>";
                $html .= "</div>";
                $html .= "</div>";
                $html .= "</td>";
                if($all_order['IncludeOrderBarcodes'] == '1')
                {
                    if (isset($image) && !empty($image)) {
                    $html .= "<td><img src='" . $image . "' height='250px;'></td>";
                    }
                }
                else
                {
                    $html .= "<td></td>";
                }
                
                $html .= "</tr>";
                $html .= "<br>";
                $html .= "<tr class='border_top_radius'>";
                $html .= "<td colspan='5'><b>Selling and Buying</b></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td colspan='3'><b>Ship To</b></td>";
                $html .= "<td colspan='2'><b>Bill To</b></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td colspan='3'><b>" . $val_data['ShippingName'] . "</b><br>";
                $html .= $val_data['ShippingAddress1'] . "<br>";
                $html .=  $val_data['ShippingAddress2'] . "<br>";
                $html .= $val_data['ShippingAddress3'] . "<br>";
                $html .= $val_data['ShippingCity'] . "," . $val_data['ShippingState'] . "<br>";
                $html .= $val_data['ShippingCountry'] . "<br>";
                if($all_order['HidePhone'] == '1')
                {
                    $html .= $val_data['ShippingPhone'] . "</td>";
                }
                
                $html .= "<td colspan='2'><b>" . $val_data['BillingName'] . "</b><br>";
                $html .= $val_data['BillingAddress1'] . "<br>";
                $html .= $val_data['BillingAddress2'] . "<br>";
                $html .= $val_data['BillingAddress3'] . "<br>";
                $html .= $val_data['BillingCity'] . "," . $val_data['ShippingState'] . "<br>";
                $html .= $val_data['BillingCountry'] . "<br>";
                if($all_order['HidePhone'] == '1')
                {
                     $html .= $val_data['BillingPhone'] . "</td>";
                }
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td style='border:1px solid black;'><b>QTY</b></td>";
                $html .= "<td style='border:1px solid black;'><b>ISBN/UPC</b></td>";
                $html .= "<td style='border:1px solid black;'><b>Condition</b></td>";
                $html .= "<td width='30%' style='border:1px solid black;'><b>Description</b></td>";
                $html .= "<td style='border:1px solid black;'><b>Media</b></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td style='border:1px solid black;width:80px;'>" . $val_data['ProductQty'] . "</td>";
                $html .= "<td style='border:1px solid black;width:100px;'>" . $val_data['ProductISBN'] . "</td>";
                $html .= "<td style='border:1px solid black;width:150px;'>" . $val_data['ProductCondition'] . "</td>";
                $html .= "<td width='30%'>" . $val_data['ProductDescription'] . "</td>";
                $html .= "<td>Hardcover</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='5' style='border:1px solid black;'><b>SKU : </b>" . $val_data['ProductSKU'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='5' style='border:1px solid black;'><b>Location : </b>" . $val_data['ProductSKU'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='5' style='border:1px solid black;'><b>Note : </b>" . $val_data['ProductDescription'] . "</td>";
                $html .= "<br>";
                $html .= "<br>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='4'></td>";
                $html .= "<td colspan='4'>";
                $html .= "<br>";
                $html .= "<br>";
                if (isset($all_order['FooterImageURL']) && !empty($all_order['FooterImageURL'])) {
                    $footerimageurl = $all_order['FooterImageURL'];
                    $allow = ['gif', 'jpg', 'png'];  // allowed extensions
                    $footer_url_info = pathinfo($footerimageurl);
                    if(in_array(isset($footer_url_info['extension']), $allow) && !empty($footer_url_info['extension'])) {
                        $html .= "<img src='" . $footerimageurl . "' height='250px;'>";
                    } 
                    else
                    {
                        $html .= "";
                    }
                    if($all_order['CentreFooter'] == '1')
                    {
                        $html .= "&nbsp;&nbsp;&nbsp;<b style='font-size:20px; text-align:center;'>" . $PackingSlipFooter . "</b>";
                    }
                    else
                    {
                        $html .= "&nbsp;&nbsp;&nbsp;<b style='font-size:20px;'>" . $PackingSlipFooter . "</b>";
                    }
                    
                }
                $html .= "</td>";
                $html .= "<td colspan='2'>";
                $html .= "</td>";
                $html .= "</tr>";
                $html .= "</tbody>";
                $html .= "</table>";
            } // Loops Ends
        } else {
            $html .= "<h1>No Records found</h1>";
        }
        $html .= "</body>";
        $html .= "</html>";
        return $html;
    }

    /*
     @author    :: Tejas
     @task_id   :: 
     @task_desc :: small
     @params    :: 
     @return    :: 
    */
    public function loadPackingSmallHtml($pdf_data)
    {
        $all_order = (new LabelSetting($this->db))->LabelSettingfindByUserId(Session::get('auth_user_id'));
        
        $image = '';
        if (isset($all_order) && !empty($all_order)) {
            $image = 'data:image/png;base64,' . base64_encode(file_get_contents(getcwd() . '/assets/images/' . $all_order['BarcodeType'] . '.png'));
            
             $PackingSlipHeader = $all_order['PackingSlipHeader'];

             $PackingSlipFooter = $all_order['PackingSlipFooter'];
        }
        // $img_barcode = \App\Library\Config::get('company_url') . '/assets/images/code39.PNG';
        $html = "";
        $html .= "";
        $html .= "<!DOCTYPE html>";
        $html .= "<html>";
        $html .= "<head>";
        $html .= "<title></title>";
        $html .= "</head>";
        $html .= "<body>";
        if (isset($pdf_data) && !empty($pdf_data)) {
            end($pdf_data);
            $key = key($pdf_data);
            foreach ($pdf_data as $key_data => $val_data) {
                $class_break = (isset($key_data) && $key_data == $key) ? '' : 'page-break';
                $html .= "<table class='table " . $class_break . "' autosize='1' id='custom_tbl' border='2' width='100%' style=''>";
                $html .= "<thead>";
                $html .= "</thead>";
                $html .= "<tbody>";
                $html .= "<tr>";
                $html .= "<td colspan='3'>";
                 if (isset($all_order['HeaderImageURL']) && !empty($all_order['HeaderImageURL'])) {
                    $headerimageurl = $all_order['HeaderImageURL'];
                    $allow = ['gif', 'jpg', 'png'];  // allowed extensions
                    $url_info = pathinfo($headerimageurl);
                    if(in_array(isset($url_info['extension']), $allow) && !empty($url_info['extension'])) {
                        $html .= "<img src='" . $headerimageurl . "' height='250px;'>&nbsp;&nbsp;&nbsp;";
                    
                    }  
                    else
                    {
                        $html .= "";
                    }
                   }
                 $html .= "</td>";
                 $html .= "<td colspan='3'>";
                if($all_order['CentreHeaderText'] == '1')
                 {
                    $html .= "<p style='font-size:20px; text-align:center;'><b>" . $PackingSlipHeader . "</b></p>";
                 }
                 else
                 {
                    $html .= "<p style='font-size:20px;'><b>" . $PackingSlipHeader . "</b></p>";
                 }
                 
                 $html .= "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='5'>";
                $html .= "<p style='margin-bottom:50px;font-size:30px;font-weight:bold;'>Order: " . $val_data['OrderId'] . "</p>";
                $html .= "<br>";
                $html .= "<p style='font-size:26px;'>(" . $val_data['MarketplaceName'] . "Order: #" . $val_data['OrderId'] . ")</p>";
                $html .= "<p style='font-size:26px;'><b>Date Ordered :</b>&nbsp;" . $val_data['OrderDate'] . "</p>";
                $html .= "<p style='font-size:26px;'><b>Shipping Method: </b>&nbsp;" . $val_data['ShippingMethod'] . "</p>";
                $html .= "</td>";
                $html .= "</tr>";
                $html .= "<br>";
                $html .= "<tr>";
                if($all_order['IncludeOrderBarcodes'] == '1')
                {
                    if (isset($image) && !empty($image)) {
                    $html .= "<td colspan='5' class='img_barcode'><center><img class='img_barcode' src='" . $image . "' height='250px;'></center></td>";
                    }
                }
                $html .= "</tr>";
                $html .= "<br>";
                $html .= "<br>";
                $html .= "<tr class='border_top_radius'>";
                $html .= "<td colspan='5'><b>Selling and Buying</b></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td colspan='3'><b>Ship To</b></td>";
                $html .= "<td colspan='2'><b>Bill To</b></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td colspan='3'><b>" . $val_data['ShippingName'] . "</b><br>";
                $html .= $val_data['ShippingAddress1'] . "<br>";
                $html .=  $val_data['ShippingAddress2'] . "<br>";
                $html .= $val_data['ShippingAddress3'] . "<br>";
                $html .= $val_data['ShippingCity'] . "," . $val_data['ShippingState'] . "<br>";
                $html .= $val_data['ShippingCountry'] . "<br>";
                 if($all_order['HidePhone'] == '1')
                {
                    $html .= $val_data['ShippingPhone'] . "</td>";
                }
                $html .= "<td colspan='2'><b>" . $val_data['BillingName'] . "</b><br>";
                $html .= $val_data['BillingAddress1'] . "<br>";
                $html .= $val_data['BillingAddress2'] . "<br>";
                $html .= $val_data['BillingAddress3'] . "<br>";
                $html .= $val_data['BillingCity'] . "," . $val_data['ShippingState'] . "<br>";
                $html .= $val_data['BillingCountry'] . "<br>";
                 if($all_order['HidePhone'] == '1')
                {
                    $html .= $val_data['BillingPhone'] . "</td>";
                }
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td style='border:1px solid black;'><b>QTY</b></td>";
                $html .= "<td style='border:1px solid black;'><b>ISBN/UPC</b></td>";
                $html .= "<td style='border:1px solid black;'><b>Condition</b></td>";
                $html .= "<td width='30%' style='border:1px solid black;'><b>Description</b></td>";
                $html .= "<td style='border:1px solid black;'><b>Media</b></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td style='border:1px solid black;width:80px;'>" . $val_data['ProductQty'] . "</td>";
                $html .= "<td style='border:1px solid black;width:100px;'>" . $val_data['ProductISBN'] . "</td>";
                $html .= "<td style='border:1px solid black;width:150px;'>" . $val_data['ProductCondition'] . "</td>";
                $html .= "<td width='30%'>" . $val_data['ProductDescription'] . "</td>";
                $html .= "<td>Hardcover</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='5' style='border:1px solid black;'><b>SKU : </b>" . $val_data['ProductSKU'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='5' style='border:1px solid black;'><b>Location : </b>" . $val_data['ProductSKU'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='5' style='border:1px solid black;'><b>Note : </b>" . $val_data['ProductDescription'] . "</td>";
                $html .= "</tr>";
                 $html .= "<tr>";
                $html .= "<td colspan='4'></td>";
                $html .= "<td colspan='4'>";
                $html .= "<br>";
                $html .= "<br>";
                if (isset($all_order['FooterImageURL']) && !empty($all_order['FooterImageURL'])) {
                    $footerimageurl = $all_order['FooterImageURL'];
                    $allow = ['gif', 'jpg', 'png'];  // allowed extensions
                    $footer_url_info = pathinfo($footerimageurl);
                    if(in_array(isset($footer_url_info['extension']), $allow) && !empty($footer_url_info['extension'])) {
                        $html .= "<img src='" . $footerimageurl . "' height='250px;'>";
                    }
                    else
                    {
                        $html .= "";
                    }
                    } 
                    if($all_order['CentreFooter'] == '1')
                    {
                        $html .= "&nbsp;&nbsp;&nbsp;<b style='font-size:20px;text-align:center;'>" . $PackingSlipFooter . "</b>";
                    }
                    else
                    {
                        $html .= "&nbsp;&nbsp;&nbsp;<b style='font-size:20px;'>" . $PackingSlipFooter . "</b>";
                    }
                    
                
                $html .= "</td>";
                $html .= "<td colspan='2'>";
                $html .= "</td>";
                $html .= "</tr>";
                $html .= "</tbody>";
                $html .= "</table>";
            } // Loops Ends
        } else {
            $html .= "<h1>No Records found</h1>";
        }
        $html .= "</body>";
        $html .= "</html>";
        return $html;
    }


    /*
     @author    :: Tejas
     @task_id   :: 
     @task_desc :: self-sticklabel
     @params    :: 
     @return    :: 
    */
    public function loadPackingSelfStickHtml($pdf_data)
    {
        $all_order = (new LabelSetting($this->db))->LabelSettingfindByUserId(Session::get('auth_user_id'));
        
        $image = '';
        if (isset($all_order) && !empty($all_order)) {
            $image = 'data:image/png;base64,' . base64_encode(file_get_contents(getcwd() . '/assets/images/' . $all_order['BarcodeType'] . '.png'));
            
             $PackingSlipHeader = $all_order['PackingSlipHeader'];

             $PackingSlipFooter = $all_order['PackingSlipFooter'];
        }
        // $img_barcode = \App\Library\Config::get('company_url') . '/assets/images/code39.PNG';
        $html = "";
        $html .= "";
        $html .= "<!DOCTYPE html>";
        $html .= "<html>";
        $html .= "<head>";
        $html .= "<title></title>";
        $html .= "</head>";
        $html .= "<body>";
        if (isset($pdf_data) && !empty($pdf_data)) {
            end($pdf_data);
            $key = key($pdf_data);
            foreach ($pdf_data as $key_data => $val_data) {
                $class_break = (isset($key_data) && $key_data == $key) ? '' : 'page-break';
                $html .= "<table class='table " . $class_break . "' autosize='1' id='custom_tbl' border='2' width='100%' style=''>";
                $html .= "<thead>";
                $html .= "</thead>";
                $html .= "<tbody>";
                $html .= "<tr>";
                $html .= "<td colspan='3'>";
                 if (isset($all_order['HeaderImageURL']) && !empty($all_order['HeaderImageURL'])) {
                    $headerimageurl = $all_order['HeaderImageURL'];
                    $allow = ['gif', 'jpg', 'png'];  // allowed extensions
                    $url_info = pathinfo($headerimageurl);
                    if(in_array(isset($url_info['extension']), $allow) && !empty($url_info['extension'])) 
                    {
                        $html .= "<img src='" . $headerimageurl . "' height='250px;'>&nbsp;&nbsp;&nbsp;";
                    
                    }  
                    else
                    {
                        $html .= "";
                    }
                   }
                 $html .= "</td>";
                 $html .= "<td colspan='3'>";
                  if($all_order['CentreHeaderText'] == '1')
                 {
                    $html .= "<p style='font-size:20px; text-align:center;'><b>" . $PackingSlipHeader . "</b></p>";
                 }
                 else
                 {
                    $html .= "<p style='font-size:20px;'><b>" . $PackingSlipHeader . "</b></p>";
                 }
                 $html .= "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='5'>";
                $html .= "<p style='margin-bottom:50px;font-size:24px;font-weight:bold;'>Order: " . $val_data['OrderId'] . "</p>";
                $html .= "<br>";
                $html .= "<p style='font-size:20px;'>(" . $val_data['MarketplaceName'] . "Order: #" . $val_data['OrderId'] . ")</p>";
                $html .= "<p style='font-size:20px;'><b>Date Ordered :</b>&nbsp;" . $val_data['OrderDate'] . "</p>";
                $html .= "<p style='font-size:20px;'><b>Shipping Method: </b>&nbsp;" . $val_data['ShippingMethod'] . "</p>";
                $html .= "</td>";
                $html .= "<div class=''>";
                $html .= "<td colspan='5' class='img_barcode'>";
                $html .= " <p class='text_left'>" . $val_data['ShippingName'] . "</p>";
                $html .= " <p class='text_left'>" . $val_data['ShippingAddress3'] . "</p>";
                $html .= " <p class='text_left'>" . $val_data['ShippingCity'] . "," . $val_data['ShippingState'] . "</p>";
                $html .= " <p class='text_left'>" . $val_data['ShippingCountry'] . "</p>";
                $html .= " <p class='text_left'>" . $val_data['ShippingPhone'] . "</p>";
                if($all_order['IncludeOrderBarcodes'] == '1')
                {
                    if (isset($image) && !empty($image)) {
                        $html .= "<img src='" . $image . "' height='250px;'>";
                    }
                }
                $html .= "</td>";
                $html .= "</div>";
                $html .= "</tr>";
                $html .= "<br>";
                $html .= "<tr class='border_top_radius'>";
                $html .= "<td colspan='7'><b>Selling and Buying</b></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td colspan='5'><b>Ship To</b></td>";
                $html .= "<td colspan='2'><b>Bill To</b></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td colspan='5'><b>" . $val_data['ShippingName'] . "</b><br>";
                $html .= $val_data['ShippingAddress1'] . "<br>";
                $html .=  $val_data['ShippingAddress2'] . "<br>";
                $html .= $val_data['ShippingAddress3'] . "<br>";
                $html .= $val_data['ShippingCity'] . "," . $val_data['ShippingState'] . "<br>";
                $html .= $val_data['ShippingCountry'] . "<br>";
                if($all_order['HidePhone'] == '1')
                {
                    $html .= $val_data['ShippingPhone'] . "</td>";
                }
                $html .= "<td colspan='2'><b>" . $val_data['BillingName'] . "</b><br>";
                $html .= $val_data['BillingAddress1'] . "<br>";
                $html .= $val_data['BillingAddress2'] . "<br>";
                $html .= $val_data['BillingAddress3'] . "<br>";
                $html .= $val_data['BillingCity'] . "," . $val_data['ShippingState'] . "<br>";
                $html .= $val_data['BillingCountry'] . "<br>";
                if($all_order['HidePhone'] == '1')
                {
                    $html .= $val_data['BillingPhone'] . "</td>";
                }
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td colspan='1' style='border:1px solid black;'><b>QTY</b></td>";
                $html .= "<td colspan='1' style='border:1px solid black;'><b>ISBN/UPC</b></td>";
                $html .= "<td scolspan='1' tyle='border:1px solid black;'><b>Condition</b></td>";
                $html .= "<td colspan='3' width='30%' style='border:1px solid black;'><b>Description</b></td>";
                $html .= "<td colspan='1' style='border:1px solid black;'><b>Media</b></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td style='border:1px solid black;width:80px;'>" . $val_data['ProductQty'] . "</td>";
                $html .= "<td style='border:1px solid black;width:100px;'>" . $val_data['ProductISBN'] . "</td>";
                $html .= "<td style='border:1px solid black;width:150px;'>" . $val_data['ProductCondition'] . "</td>";
                $html .= "<td colspan='3' width='30%'>" . $val_data['ProductDescription'] . "</td>";
                $html .= "<td>Hardcover</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='7' style='border:1px solid black;'><b>SKU : </b>" . $val_data['ProductSKU'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='7' style='border:1px solid black;'><b>Location : </b>" . $val_data['ProductSKU'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='7' style='border:1px solid black;'><b>Note : </b>" . $val_data['ProductDescription'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='4'></td>";
                $html .= "<td colspan='4'>";
                $html .= "<br>";
                $html .= "<br>";
                if (isset($all_order['FooterImageURL']) && !empty($all_order['FooterImageURL'])) {
                    $footerimageurl = $all_order['FooterImageURL'];
                    $allow = ['gif', 'jpg', 'png'];  // allowed extensions
                    $footer_url_info = pathinfo($footerimageurl);
                    if(in_array(isset($footer_url_info['extension']), $allow) && !empty($footer_url_info['extension'])) {
                        $html .= "<img src='" . $footerimageurl . "' height='250px;'>";
                     }
                     else
                    {
                        $html .= "";
                    }
                    } 
                    if($all_order['CentreFooter'] == '1')
                    {
                        $html .= "&nbsp;&nbsp;&nbsp;<b style='font-size:20px;text-align:center;'>" . $PackingSlipFooter . "</b>";
                    }
                    else
                    {
                        $html .= "&nbsp;&nbsp;&nbsp;<b style='font-size:20px;'>" . $PackingSlipFooter . "</b>";
                    }
                   
                
                $html .= "</td>";
                $html .= "<td colspan='2'>";
                $html .= "</td>";
                $html .= "</tr>";
                $html .= "</tbody>";
                $html .= "</table>";
            } // Loops Ends
        } else {
            $html .= "<h1>No Records found</h1>";
        }
        $html .= "</body>";
        $html .= "</html>";
        return $html;
    }

    /*
     @author    :: Tejas
     @task_id   :: 
     @task_desc :: 92 fold
     @params    :: 
     @return    :: 
    */
    public function loadPacking92FoldHtml($pdf_data)
    {
        $all_order = (new LabelSetting($this->db))->LabelSettingfindByUserId(Session::get('auth_user_id'));
        
        $image = '';
        if (isset($all_order) && !empty($all_order)) {
            $image = 'data:image/png;base64,' . base64_encode(file_get_contents(getcwd() . '/assets/images/' . $all_order['BarcodeType'] . '.png'));
            
             $PackingSlipHeader = $all_order['PackingSlipHeader'];

             $PackingSlipFooter = $all_order['PackingSlipFooter'];
        }
        // $img_barcode = \App\Library\Config::get('company_url') . '/assets/images/code39.PNG';
        $html = "";
        $html .= "";
        $html .= "<!DOCTYPE html>";
        $html .= "<html>";
        $html .= "<head>";
        $html .= "<title></title>";
        $html .= "</head>";
        $html .= "<body>";
        if (isset($pdf_data) && !empty($pdf_data)) {
            end($pdf_data);
            $key = key($pdf_data);
            foreach ($pdf_data as $key_data => $val_data) {
                $class_break = (isset($key_data) && $key_data == $key) ? '' : 'page-break';
                $html .= "<table class='table_left' autosize='1' style='display:block;float:left;width:20%;'>";
                $html .= "<tr>";
                $html .= "<td colspan='3'>";
                 if (isset($all_order['HeaderImageURL']) && !empty($all_order['HeaderImageURL'])) {
                    $headerimageurl = $all_order['HeaderImageURL'];
                    $allow = ['gif', 'jpg', 'png'];  // allowed extensions
                    $url_info = pathinfo($headerimageurl);
                    if(in_array(isset($url_info['extension']), $allow) && !empty($url_info['extension'])) 
                     {   
                        $html .= "<img src='" . $headerimageurl . "' height='250px;'>&nbsp;&nbsp;&nbsp;";
                    }  
                    else
                    {
                        $html .= "";
                    }
                   }
               
                $html .= "</td>";
                 $html .= "<td colspan='3'>";
                 if($all_order['CentreHeaderText'] == '1')
                 {
                    $html .= "<p style='font-size:20px; text-align:center;'><b>" . $PackingSlipHeader . "</b></p>";
                 }
                 else
                 {
                    $html .= "<p style='font-size:20px;'><b>" . $PackingSlipHeader . "</b></p>";
                 }
                 $html .= "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td><b>Order# " . $val_data['OrderId'] . "</b></td></br>";
                $html .= "</tr>";
                $html .= "<tr>";
                if($all_order['IncludeOrderBarcodes'] == '1')
                {
                    if (isset($image) && !empty($image)) {
                        $html .= "<td><img src='" . $image . "' style='width:150px;height:150px;'></td></br>";
                    }

                }
                $html .= "<td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td>";
                $html .= "<p style='margin-bottom:50px;font-size:20px;font-weight:bold;'>Order: " . $val_data['OrderId'] . "</p>";
                $html .= "<p style='font-size:14px;'>(" . $val_data['MarketplaceName'] . "Order: #" . $val_data['OrderId'] . ")</p>";
                $html .= "<p style='font-size:14px;'><b>Date Ordered :</b>&nbsp;" . $val_data['OrderDate'] . "</p>";
                $html .= "</td>";
                $html .= "</tr>";
                $html .= "</table>";
                $html .= "<table class='table " . $class_break . "' id='custom_tbl' cellpadding='8' border='2' style='display:block;float:right;margin-left:40%;margin-top:-25%;'>";
                $html .= "<thead>";
                $html .= "</thead>";
                $html .= "<tbody>";

                $html .= "<tr class='border_top_radius'>";
                $html .= "<td colspan='7'><b>Selling and Buying</b></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td colspan='5'><b>Ship To</b></td>";
                $html .= "<td colspan='2'><b>Bill To</b></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td colspan='5'><b>" . $val_data['ShippingName'] . "</b><br>";
                $html .= $val_data['ShippingAddress1'] . "<br>";
                $html .=  $val_data['ShippingAddress2'] . "<br>";
                $html .= $val_data['ShippingAddress3'] . "<br>";
                $html .= $val_data['ShippingCity'] . "," . $val_data['ShippingState'] . "<br>";
                $html .= $val_data['ShippingCountry'] . "<br>";
                if($all_order['HidePhone'] == '1')
                {
                    $html .= $val_data['ShippingPhone'] . "</td>";
                }
                $html .= "<td colspan='2'><b>" . $val_data['BillingName'] . "</b><br>";
                $html .= $val_data['BillingAddress1'] . "<br>";
                $html .= $val_data['BillingAddress2'] . "<br>";
                $html .= $val_data['BillingAddress3'] . "<br>";
                $html .= $val_data['BillingCity'] . "," . $val_data['ShippingState'] . "<br>";
                $html .= $val_data['BillingCountry'] . "<br>";
                if($all_order['HidePhone'] == '1')
                {
                    $html .= $val_data['BillingPhone'] . "</td>";
                }
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td colspan='1' style='border:1px solid black;'><b>QTY</b></td>";
                $html .= "<td colspan='1' style='border:1px solid black;'><b>ISBN/UPC</b></td>";
                $html .= "<td scolspan='1' tyle='border:1px solid black;'><b>Condition</b></td>";
                $html .= "<td colspan='3' width='30%' style='border:1px solid black;'><b>Description</b></td>";
                $html .= "<td colspan='1' style='border:1px solid black;'><b>Media</b></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td style='border:1px solid black;width:80px;'>" . $val_data['ProductQty'] . "</td>";
                $html .= "<td style='border:1px solid black;width:100px;'>" . $val_data['ProductISBN'] . "</td>";
                $html .= "<td style='border:1px solid black;width:150px;'>" . $val_data['ProductCondition'] . "</td>";
                $html .= "<td colspan='3' width='30%'>" . $val_data['ProductDescription'] . "</td>";
                $html .= "<td>Hardcover</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='7' style='border:1px solid black;'><b>SKU : </b>" . $val_data['ProductSKU'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='7' style='border:1px solid black;'><b>Location : </b>" . $val_data['ProductSKU'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='7' style='border:1px solid black;'><b>Note : </b>" . $val_data['ProductDescription'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='4'></td>";
                $html .= "<td colspan='4'>";
                $html .= "<br>";
                $html .= "<br>";
                if (isset($all_order['FooterImageURL']) && !empty($all_order['FooterImageURL'])) {
                    $footerimageurl = $all_order['FooterImageURL'];
                    $allow = ['gif', 'jpg', 'png'];  // allowed extensions
                    $footer_url_info = pathinfo($footerimageurl);
                    if(in_array(isset($footer_url_info['extension']), $allow) && !empty($footer_url_info['extension'])) {
                        $html .= "<img src='" . $footerimageurl . "' height='250px;'>";
                     }
                     else
                    {
                        $html .= "";
                    }
                    } 
                    if($all_order['CentreFooter'] == '1')
                    {
                        $html .= "&nbsp;&nbsp;&nbsp;<b style='font-size:20px;text-align:center;'>" . $PackingSlipFooter . "</b>";
                    }
                    else
                    {
                        $html .= "&nbsp;&nbsp;&nbsp;<b style='font-size:20px;'>" . $PackingSlipFooter . "</b>";
                    }
                
                $html .= "</td>";
                $html .= "<td colspan='2'>";
                $html .= "</td>";
                $html .= "</tr>";
                $html .= "</tbody>";
                $html .= "</table><br><br><br><br>";
            } // Loops Ends
        } else {
            $html .= "<h1>No Records found</h1>";
        }
        $html .= "</body>";
        $html .= "</html>";
        return $html;
    }


    /*
     @author    :: Tejas
     @task_id   :: 
     @task_desc :: mailing
     @params    :: 
     @return    :: 
    */
    public function loadPackingMailingHtml($pdf_data)
    {
        $all_order = (new LabelSetting($this->db))->LabelSettingfindByUserId(Session::get('auth_user_id'));
       
        $image = '';
        if (isset($all_order) && !empty($all_order)) {
            $image = 'data:image/png;base64,' . base64_encode(file_get_contents(getcwd() . '/assets/images/' . $all_order['BarcodeType'] . '.png'));
           
             $PackingSlipHeader = $all_order['PackingSlipHeader'];

             $PackingSlipFooter = $all_order['PackingSlipFooter'];
        }
        // $img_barcode = \App\Library\Config::get('company_url') . '/assets/images/code39.PNG';
        $html = "";
        $html .= "";
        $html .= "<!DOCTYPE html>";
        $html .= "<html>";
        $html .= "<head>";
        $html .= "<title></title>";
        $html .= "</head>";
        $html .= "<body>";
        if (isset($pdf_data) && !empty($pdf_data)) {
            end($pdf_data);
            $key = key($pdf_data);
            foreach ($pdf_data as $key_data => $val_data) {
                $class_break = (isset($key_data) && $key_data == $key) ? '' : 'page-break';
                $html .= "<div class='main_div'>";
if (isset($all_order['HeaderImageURL']) && !empty($all_order['HeaderImageURL'])) {
                    $headerimageurl = $all_order['HeaderImageURL'];
                    $allow = ['gif', 'jpg', 'png'];  // allowed extensions
                    $url_info = pathinfo($headerimageurl);
                    if(in_array(isset($url_info['extension']), $allow) && !empty($url_info['extension'])) {
                        $html .= "<img src='" . $headerimageurl . "' height='250px;'>&nbsp;&nbsp;&nbsp;<b style='font-size:20px;'>" . $PackingSlipHeader . "</b>";
                        $html .= "</br>";
                        $html .= "</br>";
                        $html .= "</br>"; 
                        $html .= "</br>";
                        $html .= "</br>";
                        $html .= "</br>";
                    }
                    else
                    {
                        $html .= "";
                    }  
                    
                   }
                
                
               if($all_order['IncludeOrderBarcodes'] == '1')
                {
                    if (isset($image) && !empty($image)) {
                        $html .= "<p><img src='" . $image . "'  weight='50px;'> </p></br>";
                    }
                }
                $html .= "</br>";
                $html .= "</br>";
                $html .= "</br>";
                $html .= "</br>";
                $html .= "<span style='font-size:32px;'>Ship To :</span><br>";
                $html .= "<span style='font-size:32px;'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>" . $val_data['ShippingName'] . "</b></span><br>";
                $html .= "<span style='font-size:32px;'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;" . $val_data['ShippingAddress1'] . "</span><br>";
                $html .= "<span style='font-size:32px;'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;" . $val_data['ShippingAddress2'] . "</span><br>";
                $html .= "<span style='font-size:32px;'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;" . $val_data['ShippingAddress3'] . "</span><br>";
                $html .= "<span style='font-size:32px;'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;" . $val_data['ShippingCity'] . "</span><br>";
                $html .= "</br>";
                $html .= "</br>";
                $html .= "</br>";
                $html .= "</br>";
                $html .= "</br>";
                $html .= "</br>";
                $html .= "</br>";
                $html .= "</br>";
                $html .= "<p></p><p></p><p></p><p></p><p></p><p></p><p></p><p></p>";
                $html .= "<p style='margin-left:155px;margin-right:155px;border:1px solid black;width:100px !important;'></p>";
                $html .= "</br>";
                $html .= "<table class='table " . $class_break . "' autosize='1' id='custom_tbl' border='2' width='100%' >";
                $html .= "<thead>";
                $html .= "</thead>";
                $html .= "<tbody>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td colspan='1' style='border:1px solid black;'><b>QTY</b></td>";
                $html .= "<td colspan='1' style='border:1px solid black;'><b>ISBN/UPC</b></td>";
                $html .= "<td scolspan='1' tyle='border:1px solid black;'><b>Condition</b></td>";
                $html .= "<td colspan='3' width='30%' style='border:1px solid black;'><b>Description</b></td>";
                $html .= "<td colspan='1' style='border:1px solid black;'><b>Media</b></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td style='border:1px solid black;width:80px;'>" . $val_data['ProductQty'] . "</td>";
                $html .= "<td style='border:1px solid black;width:100px;'>" . $val_data['ProductISBN'] . "</td>";
                $html .= "<td style='border:1px solid black;width:150px;'>" . $val_data['ProductCondition'] . "</td>";
                $html .= "<td colspan='3' width='30%'>" . $val_data['ProductDescription'] . "</td>";
                $html .= "<td>Hardcover</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='7' style='border:1px solid black;'><b>SKU : </b>" . $val_data['ProductSKU'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='7' style='border:1px solid black;'><b>Location : </b>" . $val_data['ProductSKU'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='7' style='border:1px solid black;'><b>Note : </b>" . $val_data['ProductDescription'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='4'></td>";
                $html .= "<td colspan='4'>";
                $html .= "<br>";
                $html .= "<br>";
               if (isset($all_order['FooterImageURL']) && !empty($all_order['FooterImageURL'])) {
                    $footerimageurl = $all_order['FooterImageURL'];
                    $allow = ['gif', 'jpg', 'png'];  // allowed extensions
                    $footer_url_info = pathinfo($footerimageurl);
                    if(in_array(isset($footer_url_info['extension']), $allow) && !empty($footer_url_info['extension'])) {
                        $html .= "<img src='" . $footerimageurl . "' height='250px;'>";
                     }
                     else
                    {
                        $html .= "";
                    }
                    } 
                   if($all_order['CentreFooter'] == '1')
                    {
                        $html .= "&nbsp;&nbsp;&nbsp;<b style='font-size:20px;text-align:center;'>" . $PackingSlipFooter . "</b>";
                    }
                    else
                    {
                        $html .= "&nbsp;&nbsp;&nbsp;<b style='font-size:20px;'>" . $PackingSlipFooter . "</b>";
                    }
                
                $html .= "</td>";
                $html .= "<td colspan='2'>";
                $html .= "</td>";
                $html .= "</tr>";
                $html .= "</tbody>";
                $html .= "</table>";
                $html .= "</div>";
            } // Loops Ends
        } else {
            $html .= "<h1>No Records found</h1>";
        }
        $html .= "</body>";
        $html .= "</html>";
        return $html;
    }

    /*
     @author    :: Tejas
     @task_id   :: 
     @task_desc :: Integrate Label layout
     @params    :: 
     @return    :: 
    */
    public function loadPackingIntegrateLabel($pdf_data)
    {
        $all_order = (new LabelSetting($this->db))->LabelSettingfindByUserId(Session::get('auth_user_id'));
        
        $image = '';
        
        if (isset($all_order) && !empty($all_order)) {
            $image = 'data:image/png;base64,' . base64_encode(file_get_contents(getcwd() . '/assets/images/' . $all_order['BarcodeType'] . '.png'));
            
             $PackingSlipHeader = $all_order['PackingSlipHeader'];

             $PackingSlipFooter = $all_order['PackingSlipFooter'];
        }
        // $img_barcode = \App\Library\Config::get('company_url') . '/assets/images/code39.PNG';
        $html = "";
        $html .= "";
        $html .= "<!DOCTYPE html>";
        $html .= "<html>";
        $html .= "<head>";
        $html .= "<title></title>";
        $html .= "</head>";
        $html .= "<body>";
        if (isset($pdf_data) && !empty($pdf_data)) {
            end($pdf_data);
            $key = key($pdf_data);
            foreach ($pdf_data as $key_data => $val_data) {
                $class_break = (isset($key_data) && $key_data == $key) ? '' : 'page-break';
                $html .= "<table class='top_letter' style='padding:50px;padding-left:0px;border: 1px solid black;width: 100%;text-align: left;'>";
                 $html .= "<tr>";
                $html .= "<td colspan='3'>";
                 if (isset($all_order['HeaderImageURL']) && !empty($all_order['HeaderImageURL'])) {
                    $headerimageurl = $all_order['HeaderImageURL'];
                    $allow = ['gif', 'jpg', 'png'];  // allowed extensions
                    $url_info = pathinfo($headerimageurl);
                    if(in_array(isset($url_info['extension']), $allow) && !empty($url_info['extension'])) {
                        $html .= "<img src='" . $headerimageurl . "' height='250px;'>&nbsp;";
                    }  
                    else
                    {
                        $html .= "";
                    }
                   }
                
                $html .= "</td>";
                 $html .= "<td colspan='3'>";
                 if($all_order['CentreHeaderText'] == '1')
                 {
                    $html .= "<p style='font-size:20px; text-align:center;'><b>" . $PackingSlipHeader . "</b></p>";
                 }
                 else
                 {
                    $html .= "<p style='font-size:20px;'><b>" . $PackingSlipHeader . "</b></p>";
                 }
                 $html .= "</td>";
                $html .= "</tr><br><br>";
                $html .= "<tr>";
                $html .= "<td style='font-size:28px;'><b>" . $val_data['ShippingName'] . "</b> <br>";
                $html .= "<span style='font-size:28px;'>" . $val_data['ShippingAddress1'] . "</span> <br>";
                $html .= "<span style='font-size:28px;'>" . $val_data['ShippingAddress2'] . "</span> <br>";
                $html .= "<span style='font-size:28px;'>" . $val_data['ShippingAddress3'] . "</span> <br>";
                $html .= "<span style='font-size:28px;'>" . $val_data['ShippingCity'] . "," . $val_data['ShippingState'] . "," . $val_data['ShippingZipCode'] . "</span> <br>";
                $html .= "<span style='font-size:28px;'>" . $val_data['ShippingCountry'] . "</span> <br>";
                $html .= "</tr>";
                $html .= "</table></br></br></br></br></br></br></br><p></p><p></p><p></p><p></p><p></p><p></p><p></p>";
                $html .= "<div class='main_div'>";
                $html .= "<span style='font-size:24px;'>Customer Phone #: " . $val_data['ShippingPhone'] . "</span>";
                 if($all_order['IncludeOrderBarcodes'] == '1')
                {
                    if (isset($image) && !empty($image)) {
                        $html .= "<p><img class='product_image_barcode' src='" . $image . "'  weight='50px;'> </p></br>";
                    }
                }
                $html .= "<table class='table " . $class_break . "' autosize='1' id='custom_tbl' border='2' width='100%' >";
                $html .= "<thead>";
                $html .= "</thead>";
                $html .= "<tbody>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td class='second_table_head' colspan='1' style='border:1px solid black;'><b>QTY</b></td>";
                $html .= "<td class='second_table_head' colspan='1' style='border:1px solid black;'><b>ISBN/UPC</b></td>";
                $html .= "<td class='second_table_head' scolspan='1' style='border:1px solid black;'><b>Condition</b></td>";
                $html .= "<td class='second_table_head' colspan='3' width='30%' style='border:1px solid black;'><b>Description</b></td>";
                $html .= "<td class='second_table_head' colspan='1' style='border:1px solid black;'><b>Media</b></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td style='border:1px solid black;width:80px;'>" . $val_data['ProductQty'] . "</td>";
                $html .= "<td style='border:1px solid black;width:100px;'>" . $val_data['ProductISBN'] . "</td>";
                $html .= "<td style='border:1px solid black;width:150px;'>" . $val_data['ProductCondition'] . "</td>";
                $html .= "<td colspan='3' style='border:1px solid black;width:150px;'>" . $val_data['ProductDescription'] . "</td>";
                $html .= "<td style='border:1px solid black;width:150px;'>Hardcover</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='7' style='border:1px solid black;'><b>SKU : </b>" . $val_data['ProductSKU'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='7' style='border:1px solid black;'><b>Location : </b>" . $val_data['ProductSKU'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='7' style='border:1px solid black;'><b>Note : </b>" . $val_data['ProductDescription'] . "</td>";
                $html .= "</tr>";
                $html .= "<tr>";
                $html .= "<td colspan='4'></td>";
                $html .= "<td colspan='4'>";
                $html .= "<br>";
                $html .= "<br>";
               if (isset($all_order['FooterImageURL']) && !empty($all_order['FooterImageURL'])) {
                    $footerimageurl = $all_order['FooterImageURL'];
                    $allow = ['gif', 'jpg', 'png'];  // allowed extensions
                    $footer_url_info = pathinfo($footerimageurl);
                    if(in_array(isset($footer_url_info['extension']), $allow) && !empty($footer_url_info['extension'])) {
                        $html .= "<img src='" . $footerimageurl . "' height='250px;'>";
                     }
                     else
                    {
                        $html .= "";
                    }
                    } 
                    if($all_order['CentreFooter'] == '1')
                    {
                        $html .= "&nbsp;<b style='font-size:20px;text-align:center;'>" . $PackingSlipFooter . "</b>";
                    }
                    else
                    {
                        $html .= "&nbsp;<b style='font-size:20px;'>" . $PackingSlipFooter . "</b>";
                    }
                    
                
                $html .= "</td>";
                $html .= "<td colspan='2'>";
                $html .= "</td>";
                $html .= "</tr>";

                $html .= "</tbody>";
                $html .= "</table>";
                $html .= "</div><br><br><br>";
            } // Loops Ends
        } else {
            $html .= "<h1>No Records found</h1>";
        }
        $html .= "</body>";
        $html .= "</html>";
        return $html;
    }

    /*
   @author    :: Tejas
   @task_id   :: 
   @task_desc :: load html view and generate pdf and download
   @params    :: 
   @return    :: pdf download
  */
    public function pdfGeneratePickLoad(ServerRequest $request)
    {
        $form = $request->getParsedBody();
        unset($form['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.      
        try {
            // Sanitize and Validate
            $validate = new ValidateSanitize();
            $form = $validate->sanitize($form); // only trims & sanitizes strings (other filters available)
            $validate->validation_rules(array(
                'status'    => 'required'
            ));

            $validated = $validate->run($form);
            // use validated as it is filtered and validated        
            if ($validated === false) {
                throw new Exception("Please select required fields...!", 301);
            }

            $pdf_data = (new Order($this->db))->getPickOrderStatus($form);
            // $view = $this->view->buildResponse('order/pdf_pick', ['pdf_data' => $pdf_data]);
            $packing_html = $this->loadPickinghtml($pdf_data);

            $mpdf = new Mpdf();
            $mpdf->use_kwt = true;
            $mpdf->WriteHTML($packing_html);
            $mpdf->Output('assets/order/picking/picking.pdf', 'F');
            die(json_encode(['status' => true, 'message' => 'File downloaded successfully..!', 'data' => null, 'filename' => '/picking.pdf']));
        } catch (Exception $e) {
            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();
            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            die(json_encode(['status' => false, 'message' => 'File not downloaded', 'data' => null]));
        }
    }

    /*
     @author    :: Tejas
     @task_id   :: 
     @task_desc :: 
     @params    :: 
     @return    :: 
    */
    public function loadPickinghtml($pdf_data)
    {

        $all_order = (new LabelSetting($this->db))->LabelSettingfindByUserId(Session::get('auth_user_id'));
        $image = '';
        if (isset($all_order) && !empty($all_order)) {
            $image = 'data:image/png;base64,' . base64_encode(file_get_contents(getcwd() . '/assets/images/' . $all_order['BarcodeType'] . '.png'));
        }
        $html = "";
        $html .= "";
        $html .= "<!DOCTYPE html>";
        $html .= "<html>";
        $html .= "<head>";
        $html .= "<title></title>";
        $html .= "</head>";
        $html .= "<body>";
        if (isset($pdf_data) && !empty($pdf_data)) {
            foreach ($pdf_data as $key_data => $val_data) {
                $html .= "<table class='table' id='custom_tbl' border='2' width='100%' style='border-collapse: collapse;'>";
                $html .= "<thead>";
                $html .= "</thead>";
                $html .= "<tbody>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td style='border:1px solid black;'>Order </td>";
                $html .= "<td style='border:1px solid black;'>SKU/ASIN/UPC</td>";
                $html .= "<td style='border:1px solid black;'>Location</td>";
                $html .= "<td style='border:1px solid black;'>Category</td>";
                $html .= "<td style='border:1px solid black;'>Price</td>";
                $html .= "<td style='border:1px solid black;'>QTY</td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td style='border:1px solid black;'>Barcode </td>";
                $html .= "<td style='border:1px solid black;'>Description</td>";
                $html .= "<td style='border:1px solid black;'></td>";
                $html .= "<td style='border:1px solid black;'>Condition</td>";
                $html .= "<td style='border:1px solid black;'></td>";
                $html .= "<td style='border:1px solid black;'></td>";
                $html .= "</tr>";
                $html .= "<tr style='border:1px solid black;'>";
                $html .= "<td style='border:1px solid black;'></td>";
                $html .= "<td style='border:1px solid black;'></td>";
                $html .= "<td style='border:1px solid black;'></td>";
                $html .= "<td style='border:1px solid black;'>Note</td>";
                $html .= "<td style='border:1px solid black;'></td>";
                $html .= "<td style='border:1px solid black;'></td>";
                $html .= "</tr>";
                $html .= "<tr>";
                if($all_order['IncludeOrderBarcodes'] == '1')
                {
                if (isset($image) && !empty($image)) {
                    $html .= "<td><img src='" . $image . "' width='150'/></td>";
                }
                }
                $html .= "<td>" . $val_data['ProductSKU'] . "</td>";
                $html .= "<td>" . $val_data['ProductISBN'] . "<br>" . $val_data['ProductDescription'] . "<br>" . $val_data['BillingCity'] . " ," . $val_data['BillingState'];
                $html .= "</td>";
                $html .= "<td>Hardcore<br>";
                $html .= $val_data['ProductBuyerNote'] . " - " . $val_data['ProductCondition'] . "<br></td>";
                if($all_order['ShowItemPrice'] == '1')
                {
                    $html .= "<td>" . $val_data['ProductPrice'] . "</td>";
                }
                
                $html .= "<td>" . $val_data['ProductQty'] . "</td>";
                $html .= "</tr>";
                $html .= "</tbody>";
                $html .= "</table>";
                $html .= "<br><br><br><br><br><br>";
            } // Loops Ends
        } else {
            $html .= "<h1>No Records found</h1>";
        }
        $html .= "</body>";
        $html .= "</html>";
        return $html;
    }

    public function export_Orderlist(ServerRequest $request)
    {
        try {
            $form = $request->getParsedBody();
            unset($form['__token']);
            $export_type = $form['export_formate'];

            if (!isset($form['ids']) || empty($form['ids']))
                throw new Exception("Please select checkbox", 1);

            $result_data = (new Order($this->db))->select_multiple_ids($form['ids']);

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setCellValue('A1', 'MarketPlaceId');
            $sheet->setCellValue('B1', 'OrderId');
            $sheet->setCellValue('C1', 'Status');
            $sheet->setCellValue('D1', 'Currency');
            $sheet->setCellValue('E1', 'PaymentStatus');
            $sheet->setCellValue('F1', 'PaymentMethod');
            $sheet->setCellValue('G1', 'BuyerNote');
            $sheet->setCellValue('H1', 'SellerNote');
            $sheet->setCellValue('I1', 'ShippingMethod');
            $sheet->setCellValue('J1', 'Tracking');
            $sheet->setCellValue('K1', 'Carrier');
            $sheet->setCellValue('L1', 'ShippingName');
            $sheet->setCellValue('M1', 'ShippingPhone');
            $sheet->setCellValue('N1', 'ShippingEmail');
            $sheet->setCellValue('O1', 'ShippingAddress1');
            $sheet->setCellValue('P1', 'ShippingAddress2');
            $sheet->setCellValue('Q1', 'ShippingAddress3');
            $sheet->setCellValue('R1', 'ShippingCity');
            $sheet->setCellValue('S1', 'ShippingState');
            $sheet->setCellValue('T1', 'ShippingZipCode');
            $sheet->setCellValue('U1', 'ShippingCountry');
            $sheet->setCellValue('V1', 'BillingName');
            $sheet->setCellValue('W1', 'BillingPhone');
            $sheet->setCellValue('X1', 'BillingEmail');
            $sheet->setCellValue('Y1', 'BillingAddress1');
            $sheet->setCellValue('Z1', 'BillingAddress2');
            $sheet->setCellValue('AA1', 'BillingAddress3');
            $sheet->setCellValue('AB1', 'BillingCity');
            $sheet->setCellValue('AC1', 'BillingState');
            $sheet->setCellValue('AD1', 'BillingZipCode');
            $sheet->setCellValue('AE1', 'BillingCountry');
            $rows = 2;
            foreach ($result_data as $orderd) {
                $sheet->setCellValue('A' . $rows, $orderd['MarketPlaceId']);
                $sheet->setCellValue('B' . $rows, $orderd['OrderId']);
                $sheet->setCellValue('C' . $rows, $orderd['Status']);
                $sheet->setCellValue('D' . $rows, $orderd['Currency']);
                $sheet->setCellValue('E' . $rows, $orderd['PaymentStatus']);
                $sheet->setCellValue('F' . $rows, $orderd['PaymentMethod']);
                $sheet->setCellValue('G' . $rows, $orderd['BuyerNote']);
                $sheet->setCellValue('H' . $rows, $orderd['SellerNote']);
                $sheet->setCellValue('I' . $rows, $orderd['ShippingMethod']);
                $sheet->setCellValue('J' . $rows, $orderd['Tracking']);
                $sheet->setCellValue('K' . $rows, $orderd['Carrier']);
                $sheet->setCellValue('L' . $rows, $orderd['ShippingName']);
                $sheet->setCellValue('M' . $rows, $orderd['ShippingPhone']);
                $sheet->setCellValue('N' . $rows, $orderd['ShippingEmail']);
                $sheet->setCellValue('O' . $rows, $orderd['ShippingAddress1']);
                $sheet->setCellValue('P' . $rows, $orderd['ShippingAddress2']);
                $sheet->setCellValue('Q' . $rows, $orderd['ShippingAddress3']);
                $sheet->setCellValue('R' . $rows, $orderd['ShippingCity']);
                $sheet->setCellValue('S' . $rows, $orderd['ShippingState']);
                $sheet->setCellValue('T' . $rows, $orderd['ShippingZipCode']);
                $sheet->setCellValue('U' . $rows, $orderd['ShippingCountry']);
                $sheet->setCellValue('V' . $rows, $orderd['BillingName']);
                $sheet->setCellValue('W' . $rows, $orderd['BillingPhone']);
                $sheet->setCellValue('X' . $rows, $orderd['BillingEmail']);
                $sheet->setCellValue('Y' . $rows, $orderd['BillingAddress1']);
                $sheet->setCellValue('Z' . $rows, $orderd['BillingAddress2']);
                $sheet->setCellValue('AA' . $rows, $orderd['BillingAddress3']);
                $sheet->setCellValue('AB' . $rows, $orderd['BillingCity']);
                $sheet->setCellValue('AC' . $rows, $orderd['BillingState']);
                $sheet->setCellValue('AD' . $rows, $orderd['BillingZipCode']);
                $sheet->setCellValue('AE' . $rows, $orderd['BillingCountry']);

                $rows++;
            }

            if ($export_type == 'xlsx' || $export_type == 'csv') {
                $this->view->flash([
                    'alert' => 'Order Data sucessfully export..!',
                    'alert_type' => 'success'
                ]);

                if ($export_type == 'xlsx') {
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    header('Content-Disposition: attachment; filename="order.xlsx"');
                    header('Cache-Control: max-age=0');
                    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
                    $writer->save('order.xlsx');

                    die(json_encode(['status' => true, 'filename' => '/order.xlsx']));
                } else if ($export_type == 'csv') {
                    $writer = new WriteCsv($spreadsheet);
                    header('Content-Type: application/csv');
                    header('Content-Disposition: attachment; filename="order.csv"');
                    $writer->save("order.csv");
                    die(json_encode(['status' => true, 'filename' => '/order.csv']));
                }
            }
        } catch (Exception $ex) {
            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $ex->getMessage();
            $res['ex_message'] = $ex->getMessage();
            $res['ex_code'] = $ex->getCode();
            $res['ex_file'] = $ex->getFile();
            $res['ex_line'] = $ex->getLine();
            $validated['alert'] = $ex->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            die(json_encode($res));
        }
    }

    /*
     @author    :: Tejas
     @task_id   :: confirmation files upload via dropzone
     @task_desc :: confiramtion text file upload read text file and update the orderinventory table
     @params    :: File with txt extention
    */
    public function confirmFilesUpload(ServerRequest $request)
    {
        $form = $request->getParsedBody();
        unset($form['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.      
        try {

            /* File upload validation ends */
            if (isset($_FILES['file']['error']) && $_FILES['file']['error'] > 0) {
                throw new Exception('Please Upload Inventory file...!', 301);
            }

            $allowed_extentions = ['txt'];
            $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            if (!isset($ext, $allowed_extentions) && !in_array($ext, $allowed_extentions)) {
                throw new Exception('File type ' . $ext . ' is not allowed...! File types allowed are ' . implode(', ', $allowed_extentions), 1);
            }

            $file = fopen($_FILES['file']['tmp_name'], "r");
            $uiee_arr = array();
            while (!feof($file)) {
                $uiee_arr[] = fgets($file);
            }

            $order_arr = [];
            $set_order_arr = [];
            if (isset($uiee_arr) && !empty($uiee_arr)) {
                foreach ($uiee_arr as $key_data => $val_data) {
                    if ($key_data == 0)
                        continue;

                    if (!empty($val_data)) {
                        $temp_order = explode(" ", $val_data);
                        $order_arr['OrderId'] = (isset($temp_order[0]) && !empty($temp_order[0])) ? $temp_order[0] : "";
                        $order_arr['Status'] = (isset($temp_order[1]) && !empty($temp_order[1])) ? $temp_order[1] : "";
                        $order_arr['IsConfirmFiles'] = 1;
                        if (isset($temp_order[2]) && !empty($temp_order[2])) {
                            $order_arr['Tracking'] = (isset($temp_order[2]) && !empty($temp_order[2])) ? $temp_order[2] : "";
                        } else {
                            unset($order_arr['Tracking']);
                        }

                        if (isset($temp_order[3]) && !empty($temp_order[3])) {
                            $order_arr['Carrier'] = (isset($temp_order[3]) && !empty($temp_order[3])) ? $temp_order[3] : "";
                        } else {
                            unset($order_arr['Carrier']);
                        }
                        $set_order_arr[] = $order_arr;
                    }
                } // Loops Ends
            }

            $is_update = $this->insertOrUpdateConfirmFiles($set_order_arr);
            $file_details = (new Order($this->db))->getAllConfirmationFiles();
            $counter = (isset($file_details) && !empty($file_details)) ? $file_details['FileId'] + 1 : 1;

            $file_name['FileName'] = 'Trackz Confirm ' . $counter . '.txt';
            $file_name['UploadDate'] = date('Y-m-d H:i:s');
            $file_name['Status'] = (isset($is_update['status']) && $is_update['status'] == true) ? 1 : 0;
            $file_name['FileId'] = $counter;
            $file_name['OrderId'] = date('Ymd') . 'trackz-' . $this->random_strings(6);
            $file_name['AdditionalData'] = (isset($is_update['data']['addtional_info']) && !empty($is_update['data']['addtional_info'])) ? $is_update['data']['addtional_info'] : [];

            $file_details = (new Order($this->db))->addConfirmFileHandle($file_name);
            if (isset($is_update['status']) && $is_update['status']) {
                $this->view->flash([
                    'alert' => 'Order are updated successfully..!',
                    'alert_type' => 'success'
                ]);
                $res['status'] = true;
                $res['data'] = [];
                $res['message'] = 'Order are updated successfully..!';
                die(json_encode($res));
            } else {
                throw new Exception("Order are not updated...!", 301);
            }

            fclose($file);
        } catch (Exception $e) {

            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();
            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            die(json_encode(['status' => false, 'message' => 'File not uploaded', 'data' => null]));
        }
    }

    /*
    * insertOrUpdateConfirmFiles - find order update or insert
    *
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names
    * @return boolean
    */
    public function insertOrUpdateConfirmFiles($data)
    {
        $is_update = false;
        $addtional_info = [];
        foreach ($data as $key => $value) {
            $addtional_info[] = $value;
            $user_details = (new Order($this->db))->findByOrderID($value['OrderId'], Session::get('auth_user_id'));
            if (isset($user_details) && !empty($user_details)) { // update 
                // If not updated than only  update records to avoid duplication
                if (isset($user_details['IsConfirmFiles']) && $user_details['IsConfirmFiles'] == 0) {
                    $data['Updated'] = date('Y-m-d H:i:s');
                    $result = (new Order($this->db))->editOrder($user_details['Id'], $value);
                    $is_update = true;
                }
            }
        } // Loops Ends  
        if ($is_update == true) {
            $resp['status'] = true;
            $resp['message'] = 'Record found successfully...!';
            $resp['data'] = ['addtional_info' => json_encode($addtional_info)];
        } else {
            $resp['status'] = false;
            $resp['message'] = 'Record not found...!';
            $resp['data'] = ['addtional_info' => json_encode($addtional_info)];
        }
        return $resp;
    }

    /*
     @author    :: Tejas
     @task_id   :: confirmation files download
     @task_desc :: confiramtion text file download write file
     @params    :: File with txt extention
    */
    public function confirmFilesDownload($Id)
    {
        $Id = $Id->getattributes();
        try {
            if ((isset($Id) && is_array($Id)) && sizeof($Id) > 0) {
                $fileData = (new Order($this->db))->findConfirmFileId($Id['Id']);
                // echo '<pre> Test 1 :: Starts';
                // print_r($fileData);
                // echo '</pre>';
                // die('LOOP ENDS HERE');
                $error_count = 0;
                if (isset($fileData['Status']) && $fileData['Status'] == 0) {
                    if (isset($fileData['AdditionalData']) && !empty($fileData['AdditionalData'])) {
                        $error_count = sizeof(json_decode($fileData['AdditionalData']));
                    }
                }

                $success_count = 0;
                if (isset($fileData['Status']) && $fileData['Status'] == 1) {
                    if (isset($fileData['AdditionalData']) && !empty($fileData['AdditionalData'])) {
                        $success_count = sizeof(json_decode($fileData['AdditionalData']));
                    }
                }
                // $error_count = (isset($fileData['Status']) && $fileData['Status'] == 0) ? sizeof(json_decode($fileData['AdditionalData'])) : 0;
                // $success_count = (isset($fileData['Status']) && $fileData['Status'] == 1) ? sizeof(json_decode($fileData['AdditionalData'])) : 0;
                $AdditionalData = (isset($fileData['AdditionalData']) && !empty($fileData['AdditionalData'])) ? json_decode($fileData['AdditionalData']) : null;
                $AdditionalDataSize = (isset($AdditionalData) && !empty($AdditionalData)) ? sizeof($AdditionalData) : 0;

                if (isset($fileData) && !empty($fileData)) {
                    $fp = fopen(getcwd() . "/assets/" . $fileData['OrderId'] . ".txt", 'w');
                    fwrite($fp, 'File ID: ' . $fileData['OrderId'] . "\n");
                    fwrite($fp, "Summary: \n");
                    fwrite($fp, 'Total Orders Processed:' . $AdditionalDataSize . "\n");
                    fwrite($fp, "Success Count: $success_count \n");
                    fwrite($fp, "Error Count: $error_count \n");
                    fwrite($fp, "\n");
                    fwrite($fp, "Detailed Report: \n");
                    if (isset($AdditionalData) && !empty($AdditionalData)) {
                        foreach ($AdditionalData as $key_data => $val_data) {
                            fwrite($fp, "[" . date("H:i:s", strtotime($fileData['UploadDate'])) . "]" . " set status of " . $val_data->OrderId . " to " . $val_data->Status . "\n");
                        } // Loops Ends
                    }
                    fclose($fp);

                    $validated['alert'] = 'Order file downloaded successfully..!';
                    $validated['alert_type'] = 'success';
                    $this->view->flash($validated);

                    $res['status'] = true;
                    $res['data'] = array();
                    $res['message'] = 'Order file downloaded successfully..!';
                    $res['filename'] = '/assets/' . $fileData['OrderId'] . ".txt";
                    echo json_encode($res);
                    exit;
                } else {
                    throw new Exception("No Orders found to write file", 1);
                }
            } else {
                throw new Exception("Error Processing Request", 1);
            }
        } catch (Exception $e) {

            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();
            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            die(json_encode(['status' => false, 'message' => 'File not uploaded', 'data' => null, 'filename' => 'null']));
        }
    }

    /*
     @author    :: Tejas
     @task_id   :: confirmation files view
     @task_desc :: confiramtion text file download write file
     @params    :: File with txt extention
    */
    public function confirmFilesView($Id)
    {
        $Id = $Id->getattributes();
        try {
            if ((isset($Id) && is_array($Id)) && sizeof($Id) > 0) {
                $fileData = (new Order($this->db))->findConfirmFileId($Id['Id']);
                $error_count = (isset($fileData['Status']) && $fileData['Status'] == 0) ? sizeof(json_decode($fileData['AdditionalData'])) : 0;
                $success_count = (isset($fileData['Status']) && $fileData['Status'] == 1) ? sizeof(json_decode($fileData['AdditionalData'])) : 0;
                $AdditionalData = (isset($fileData['AdditionalData']) && !empty($fileData['AdditionalData'])) ? json_decode($fileData['AdditionalData']) : null;

                if (isset($fileData) && !empty($fileData)) {
                    $fp = fopen(getcwd() . "/assets/" . $fileData['OrderId'] . ".txt", 'w');
                    fwrite($fp, 'File ID: ' . $fileData['OrderId'] . "\n");
                    fwrite($fp, "Summary: \n");
                    fwrite($fp, 'Total Orders Processed:' . sizeof(json_decode($fileData['AdditionalData'])) . "\n");
                    fwrite($fp, "Success Count: $success_count \n");
                    fwrite($fp, "Error Count: $error_count \n");
                    fwrite($fp, "\n");
                    fwrite($fp, "Detailed Report: \n");
                    if (isset($AdditionalData) && !empty($AdditionalData)) {
                        foreach ($AdditionalData as $key_data => $val_data) {
                            fwrite($fp, "[" . date("H:i:s", strtotime($fileData['UploadDate'])) . "]" . " set status of " . $val_data->OrderId . " to " . $val_data->Status . "\n");
                        } // Loops Ends
                    }
                    fclose($fp);

                    $validated['alert'] = 'Order file downloaded successfully..!';
                    $validated['alert_type'] = 'success';
                    $this->view->flash($validated);

                    $res['status'] = true;
                    $res['data'] = array();
                    $res['message'] = 'Order file downloaded successfully..!';
                    $res['filename'] = '/assets/' . $fileData['OrderId'] . ".txt";
                    echo json_encode($res);
                    exit;
                } else {
                    throw new Exception("No Orders found to write file", 1);
                }
            } else {
                throw new Exception("Error Processing Request", 1);
            }
        } catch (Exception $e) {

            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();
            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            die(json_encode(['status' => false, 'message' => 'File not uploaded', 'data' => null, 'filename' => 'null']));
        }
    }


    /*
    * filterConfirmFileStatus - Update Batch Move
    * @param  $form  - Array of form fields, name match Database Fields
    *                  Form Field Names MUST MATCH Database Column Names   
    * @return boolean 
    */
    public function filterConfirmFileStatus(ServerRequest $request)
    {
        try {
            $methodData = $request->getParsedBody();
            unset($methodData['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.        

            if (isset($methodData['file_status']) && ($methodData['file_status'] == 'all' || $methodData['file_status'] == 'done' || $methodData['file_status'] == 'failed')) {
                $status = [0, 1];
                if ($methodData['file_status'] == 'done') {
                    $status = [1];
                } else if ($methodData['file_status'] == 'done') {
                    $status = [0];
                }
                $result = (new Order($this->db))->findConfirmFileStatus($status);
            } else {
                throw new Exception("No Result found...!", 1);
            }

            if (isset($result) && !empty($result)) {
                $this->view->flash([
                    'alert' => 'Files result get successfully..!',
                    'alert_type' => 'success'
                ]);

                return $this->view->buildResponse('order/confirmation_file', ['all_order' => $result]);
            } else {
                throw new Exception("Search result not found...!", 301);
            }
        } catch (Exception $e) {

            $res['status'] = false;
            $res['data'] = [];
            $res['message'] = $e->getMessage();
            $res['ex_message'] = $e->getMessage();
            $res['ex_code'] = $e->getCode();
            $res['ex_file'] = $e->getFile();
            $res['ex_line'] = $e->getLine();
            $validated['alert'] = $e->getMessage();
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            return $this->view->buildResponse('order/confirmation_file', ['all_order' => []]);
        }
    }
}

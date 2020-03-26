<?php declare (strict_types = 1);

namespace App\Controllers\Marketplace;

use App\Library\Config;
use App\Library\Views;
use App\Models\Marketplace\Marketplace;
use App\Library\ValidateSanitize\ValidateSanitize;
use Laminas\Diactoros\ServerRequest;
use PDO;

class MarketplaceController
{
    private $view;
    private $db;

    public function __construct(Views $view, PDO $db)
    {
        $this->view = $view;
        $this->db = $db;    
    }

    public function view()
    {   
        $result_data = (new Marketplace($this->db))->get_all();
        return $this->view->buildResponse('marketplace/view', ['marketplace' => $result_data]);
    }

    public function add()
    {
        $market_places = Config::get('market_places');
        return $this->view->buildResponse('marketplace/add', ['market_places' => $market_places]);
    }

    public function addSecond(ServerRequest $request)
    { 
        $form = $request->getParsedBody();
        unset($form['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.
       
        $validate = new ValidateSanitize();
        $form = $validate->sanitize($form); // only trims & sanitizes strings (other filters available)
      
        $validate->validation_rules(array(
            'market_stores'    => 'required'
        ));

        $validated = $validate->run($form,true);
        // use validated as it is filtered and validated        
        if ($validated === false) {
            $validated['alert'] = 'Sorry, we could not got to next step.  Please try again.';
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            return $this->view->redirect('/marketplace/dashboard/step2/tejas');
        }

        $market_price = Config::get('market_price');
        return $this->view->buildResponse('marketplace/add_step_second', ['market_stores' => $form['market_stores'],'market_price' => $market_price]);
    }

    public function addThree(ServerRequest $request)
    {   
        $form = $request->getParsedBody();
        unset($form['__token']); // remove CSRF token or PDO bind fails, too many arguments, Need to do everytime.
     
        $validate = new ValidateSanitize();
        $form = $validate->sanitize($form); // only trims & sanitizes strings (other filters available)
    
        $validate->validation_rules(array(
            'EmailAddress'    => 'required|valid_email',
            'MarketName'    => 'required',
            'SellerID'    => 'required',
            'Password'    => 'required',
            'FtpId'    => 'required',
            'FtpPwd'    => 'required',
            'PrependVenue'    => 'required',
            'AppendVenue'    => 'required',
            'IncreaseMinMarket'    => 'required',
            'FileFormat'    => 'required',
            'FtpAppendVenue'    => 'required',
            'SuspendExport'    => 'required',
            'SendDeletes'    => 'required',
            'MarketAcceptPrice'    => 'required',
            'MarketAcceptPriceVal'    => 'required',
            'MarketAcceptPriceValMulti'    => 'required',
            'MarketSpecificPrice'    => 'required',
            'MarketAcceptPriceVal2'    => 'required',
            'MarketAcceptPriceValMulti2'    => 'required',
        ));

        // Add filters for non-strings (integers, float, emails, etc) ALWAYS Trim
          $validate->filter_rules(array(
            'EmailAddress'    => 'trim|sanitize_email',            
        ));
        
        $validated = $validate->run($form);
      
        // use validated as it is filtered and validated        
        if ($validated === false) {                     
            $validated['alert'] = 'Sorry, Please fill marketplace data.  Please try again.';
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            return $this->view->redirect('/marketplace/dashboard');
        }

        $form_insert_data = array(
            'EmailAddress' => (isset($form['EmailAddress']) && !empty($form['EmailAddress']))?$form['EmailAddress']:null,
            'MarketName' => (isset($form['MarketName']) && !empty($form['MarketName']))?$form['MarketName']:null,
            'SellerID' => (isset($form['SellerID']) && !empty($form['SellerID']))?$form['SellerID']:null,
            'Password' => (isset($form['Password']) && !empty($form['Password']))?$form['Password']:null,
            'FtpUserId' => (isset($form['FtpId']) && !empty($form['FtpId']))?$form['FtpId']:null,
            'FtpPassword' => (isset($form['FtpPwd']) && !empty($form['FtpPwd']))?$form['FtpPwd']:null,
            'PrependVenue' => (isset($form['PrependVenue']) && !empty($form['PrependVenue']))?$form['PrependVenue']:null,
            'AppendVenue' => (isset($form['AppendVenue']) && !empty($form['AppendVenue']))?$form['AppendVenue']:null,
            'IncreaseMinMarket' => (isset($form['IncreaseMinMarket']) && !empty($form['IncreaseMinMarket']))?$form['IncreaseMinMarket']:null,
            'FileFormat' => (isset($form['FileFormat']) && !empty($form['FileFormat']))?$form['FileFormat']:null,
            'FtpAppendVenue' => (isset($form['FtpAppendVenue']) && !empty($form['FtpAppendVenue']))?$form['FtpAppendVenue']:null,
            'SuspendExport' => (isset($form['SuspendExport']) && !empty($form['SuspendExport']))?$form['SuspendExport']:null,
            'SendDeletes' => (isset($form['SendDeletes']) && !empty($form['SendDeletes']))?$form['SendDeletes']:null,
            'MarketAcceptPrice' => (isset($form['MarketAcceptPrice']) && !empty($form['MarketAcceptPrice']))?$form['MarketAcceptPrice']:null,
            'MarketAcceptPriceVal' => (isset($form['MarketAcceptPriceVal']) && !empty($form['MarketAcceptPriceVal']))?$form['MarketAcceptPriceVal']:null,
            'MarketAcceptPriceValMulti' => (isset($form['MarketAcceptPriceValMulti']) && !empty($form['MarketAcceptPriceValMulti']))?$form['MarketAcceptPriceValMulti']:null,
            'MarketSpecificPrice' => (isset($form['MarketSpecificPrice']) && !empty($form['MarketSpecificPrice']))?$form['MarketSpecificPrice']:null,
            'MarketAcceptPriceVal2' => (isset($form['MarketAcceptPriceVal2']) && !empty($form['MarketAcceptPriceVal2']))?$form['MarketAcceptPriceVal2']:null,
            'MarketAcceptPriceValMulti2' => (isset($form['MarketAcceptPriceValMulti2']) && !empty($form['MarketAcceptPriceValMulti2']))?$form['MarketAcceptPriceValMulti2']:null,
        );

        $inserted_result = (new Marketplace($this->db))->addMarketplace($form_insert_data);

        if(isset($inserted_result) && $inserted_result){
            return $this->view->buildResponse('marketplace/add_step_three');
        }else{
            $validated['alert'] = 'Sorry, we could not add marketplace.  Please try again.';
            $validated['alert_type'] = 'danger';
            $this->view->flash($validated);
            return $this->view->redirect('/marketplace/dashboard/step2');
        }
        
        $this->view->flash($validated);
        return $this->view->redirect('/marketplace/dashboard/step2');
    }

    public function editMarketplace(ServerRequest $request, $Id = [])
    {      

        $to_update = (new Marketplace($this->db))->findById($Id['Id']);

        if(is_array($to_update) && !empty($to_update)){

        }else{
            $this->view->flash([
                        'alert' => 'Failed to update marketplace. Please ensure all input is filled out correctly.',
                        'alert_type' => 'danger'
                    ]);
                    return $this->view->buildResponse('/marketplace/edit', [
                        'update_id' => $methodData['update_id'],
                        'update_name' => $methodData['Name'],
                        'update_delivery' => $methodData['DeliveryTime'],
                        'update_fee' => $methodData['InitialFee'],
                        'update_discount_fee' => $methodData['DiscountFee'],
                        'update_minimum' => $methodData['Minimum']
                    ]);

        }
        // $updated = (new ShippingMethod($this->db))->update($methodData);
        // if ($updated)
        // {
        //     $this->view->flash([
        //         'alert' => 'Successfully updated shipping method.',
        //         'alert_type' => 'success'
        //     ]);
        //     return $this->view->redirect('/account/shipping-methods');
        // }
        // else
        // {
        //     $this->view->flash([
        //         'alert' => 'Failed to update shipping method. Please ensure all input is filled out correctly.',
        //         'alert_type' => 'danger'
        //     ]);
        //     return $this->view->buildResponse('/account/shipping/create', [
        //         'update_id' => $methodData['update_id'],
        //         'update_name' => $methodData['Name'],
        //         'update_delivery' => $methodData['DeliveryTime'],
        //         'update_fee' => $methodData['InitialFee'],
        //         'update_discount_fee' => $methodData['DiscountFee'],
        //         'update_minimum' => $methodData['Minimum']
        //     ]);
        // }
    }

}

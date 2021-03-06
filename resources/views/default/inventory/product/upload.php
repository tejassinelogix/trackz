<?php
$title_meta = 'Product File Upload for Your Tracksz Store, a Multiple Market Product Management Service';
$description_meta = 'Product Listing for your Tracksz Store, a Multiple Market Product Management Service';
?>
<?= $this->layout('layouts/backend', ['title' => $title_meta, 'description' => $description_meta]) ?>

<?= $this->start('page_content') ?>
<!-- .wrapper -->
<div class="wrapper">
    <!-- .page -->
    <div class="page">
        <!-- .page-inner -->
        <div class="page-inner">
            <!-- .page-title-bar -->
            <header class="page-title-bar">
                <!-- title -->
                <div class="mb-3 d-flex justify-content-between">
                    <h1 class="page-title"> <?= _('Product File Upload') ?> </h1>
                </div>
                <p class="text-muted"> <?= _('This is where upload Product for the current Active Store: ') ?><strong> <?= \Delight\Cookie\Cookie::get('tracksz_active_name') ?></strong></p>
                <?php if (isset($alert) && $alert) : ?>
                    <div class="col-sm-12 alert alert-<?= $alert_type ?> text-center"><?= $alert ?></div>
                <?php endif ?>
            </header><!-- /.page-title-bar -->
            <div class="page-section">
                <!-- .page-section starts -->
                <div class="card-deck-xl">
                    <!-- .card-deck-xl starts -->
                    <div class="card card-fluid">
                        <!-- .card card-fluid starts -->
                        <div class="card-body">
                            <!-- .card-body starts -->
                            <form name="product_ftp_request" id="product_ftp_request" action="<?= \App\Library\Config::get('company_url') ?>/product/ftpupload" method="POST" enctype="multipart/form-data" data-parsley-validate>
                                <!-- form starts -->

                                <div class="container">
                                    <!-- Container Starts -->
                                    <div class="row">
                                        <!-- Row Starts -->

                                        <div class="col-sm">
                                            <!-- col-sm Group Left Starts -->

                                            <div class="form-group">
                                                <select name="MarketName" id="MarketName" class="browser-default custom-select market_stores_select">
                                                    <option value="" selected><?= _('Select Marketplace...') ?></option>
                                                    <?php
                                                    if (isset($market_places) && is_array($market_places) && !empty($market_places)) {
                                                        foreach ($market_places as $mar_key => $mar_val) { ?>
                                                            <option value="<?php echo $mar_val['MarketName']; ?>"><?php echo $mar_val['MarketName']; ?></option>
                                                        <?php }
                                                    } else { ?>
                                                        <option selected><?= _('No Marketplace found...') ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>

                                            <div class="form-group">
                                                <label for="ProductUpload"><?= _('Sample CSV File') ?> &nbsp;&nbsp; <a href="<?= \App\Library\Config::get('company_url') . '/assets/inventory/product_update.csv'; ?>" class="product_csv"><i class="fas fa-file-csv"></i></a></label>
                                                <div class="custom-file">
                                                    <input type="file" class="custom-file-input" id="ProductUpload" name="ProductUpload" multiple=""> <label class="custom-file-label" for="ProductUpload" data-parsley-required-message="<?= _('Select Product File Upload') ?>" data-parsley-group="fieldset01" required><?= _('Choose file') ?></label>
                                                </div>
                                            </div>
                                        </div> <!-- col-sm Group Left Ends -->

                                        <div class="col-sm mt-3 pt-3">
                                            <!-- col-sm Group Right Starts -->
                                        </div> <!-- col-sm Group Right Ends -->

                                    </div> <!-- Row Ends -->
                                </div> <!-- Container Ends -->

                                <button type="submit" class="btn btn-primary"><?= _('Submit') ?> </button>

                            </form> <!-- form ends -->
                        </div> <!-- Card Body ends -->
                    </div> <!-- .card card-fluid ends -->
                </div> <!-- .card-deck-xl ends -->
            </div> <!-- .page-section ends -->


        </div><!-- /.page-inner -->
    </div><!-- /.page -->
</div><!-- /.wrapper -->
<?= $this->stop() ?>

<?php $this->start('plugin_js') ?>
<?= $this->stop() ?>

<?php $this->start('page_js') ?>
<?= $this->stop() ?>

<?php $this->start('footer_extras') ?>
<?= $this->stop() ?>
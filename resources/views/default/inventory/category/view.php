<?php
$title_meta = 'Category Listing for Your Tracksz Store, a Multiple Marketplace Management Service';
$description_meta = 'Category Listing for your Tracksz Store, a Multiple Marketpalce Management Service';
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
                <div class="d-flex flex-column flex-md-row">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item">
                                <a href="/account/panel" title="Tracksz Account Dashboard"><i class="breadcrumb-icon fa fa-angle-left mr-2"></i><?= ('Dashboard') ?></a>
                            </li>
                            <li class="breadcrumb-item active"><?= ('Stores') ?></li>
                        </ol>
                    </nav>
                    <!-- Insert Active Store Header -->
                    <?php $this->insert('partials/active_store'); ?>
                </div>
                <!-- title -->
                <!-- title -->
                <div class="mb-3 d-flex justify-content-between">
                    <h1 class="page-title"> <?= _('Category Listing123') ?> </h1>
                </div>
                <p class="text-muted"> <?= _('This is where you can modify, and delete Category for the current Active Store: ') ?><strong> <?= \Delight\Cookie\Cookie::get('tracksz_active_name') ?></strong></p>
                <a href="/category/add" class="btn btn-sm btn-primary" title="<?= _('Add Category') ?>"><?= _('Add Category') ?></a>
                <?php if (isset($alert) && $alert) : ?>
                    <div class="col-sm-12 alert alert-<?= $alert_type ?> text-center"><?= $alert ?></div>
                <?php endif ?>
            </header><!-- /.page-title-bar -->
            <div class="page-section">
                <!-- .page-section starts -->
                <div class="card card-fluid">
                    <!-- .card card-fluid starts -->
                    <div class="card-body">
                        <!-- .card-body starts -->
                        <?php

                        if (is_array($all_category) &&  count($all_category) > 0) : ?>
                            <table id="category_table" name="category_table" class="table table-striped table-bordered nowrap" style="width:100%">
                                <thead>
                                    <tr>
                                        <th><?= _('Name') ?></th>
                                        <th><?= _('Description') ?></th>
                                        <th><?= _('Image') ?></th>
                                        <th><?= _('Action') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_category as $category) : ?>
                                        <tr>
                                            <?php
                                            $img_path = '';
                                            $img_path = \App\Library\Config::get('company_url') . '/assets/images/category/' . $category['Image'];
                                            ?>
                                            <td><?= $category['Name'] ?></td>
                                            <td><?= $category['Description'] ?></td>
                                            <td><?= (isset($category['Image']) && !empty($category['Image'])) ? "<img height=50 width=50 src='" . $img_path . "' >" : ""; ?></td>
                                            <td> <?php
                                                    $button = '';
                                                    $edit_button = '<a href="' . \App\Library\Config::get('company_url') . '/category/edit/' . $category['Id'] . '" class="btn btn-sm btn-icon btn-secondary btn_edit" title="edit"><i class="fa fa-pencil-alt" data-toggle="tooltip" data-placement="left" title="Edit this Category"></i></a> &nbsp;';
                                                    $delete_button = '<a href="#delete-' . $category['Id'] . '" delete_id="' . $category['Id'] . '" class="btn btn-sm btn-icon btn-danger btn_delete" title="delete"><i class="far fa-trash-alt" data-toggle="tooltip" data-placement="left" title="Delete this Category"></i> </a>';
                                                    $button .= $edit_button;
                                                    $button .= $delete_button;
                                                    echo $button;

                                                    ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                    </div><!-- card-body ends -->
                </div><!-- /.card card-fluid ends -->
            </div><!-- /.page-section ends -->

        </div><!-- /.page-inner -->
    </div><!-- /.page -->
</div><!-- /.wrapper -->
<?= $this->stop() ?>

<?php $this->start('plugin_js') ?>
<script src="/assets/vendor/pace/pace.min.js"></script>
<script src="/assets/vendor/stacked-menu/stacked-menu.min.js"></script>
<script src="/assets/vendor/perfect-scrollbar/perfect-scrollbar.min.js"></script>
<script src="/assets/javascript/pages/category.js"></script>
<?= $this->stop() ?>

<?php $this->start('page_js') ?>
<?= $this->stop() ?>

<?php $this->start('footer_extras') ?>
<?= $this->stop() ?>

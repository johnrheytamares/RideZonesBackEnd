<html lang="HTML5">
<head>    <title>PHP Quick Start</title>  </head>
<body>
<?php

require ROOT_DIR . '/vendor/autoload.php';

// Use the Configuration class 
use Cloudinary\Configuration\Configuration;

// Configure an instance of your Cloudinary cloud
Configuration::instance('cloudinary://116864172311287:**********@dtasoaypk?secure=true');

// Use the UploadApi class for uploading assets
use Cloudinary\Api\Upload\UploadApi;

// Upload the image
$upload = new UploadApi();

echo '******Upload response******';
echo '<br>';
echo '<pre>';
echo json_encode(
    $upload->upload('https://res.cloudinary.com/demo/image/upload/flower.jpg', [
        'public_id' => 'flower_sample',
        'use_filename' => true,
        'overwrite' => true]),
    JSON_PRETTY_PRINT
);
echo '</pre>';

// Use the AdminApi class for managing assets
use Cloudinary\Api\Admin\AdminApi;

// Get the asset details
$admin = new AdminApi();
echo '******Asset details******';
echo '<br>';
echo '<pre>';
echo json_encode($admin->asset('flower_sample', [
    'colors' => true]), JSON_PRETTY_PRINT
);
echo '</pre>';

// Use the Resize transformation group and the ImageTag class
use Cloudinary\Transformation\Resize;
use Cloudinary\Transformation\Background;
use Cloudinary\Tag\ImageTag;

// Create the image tag with the transformed image
$imgtag = (new ImageTag('flower_sample'))
    ->resize(Resize::pad()
        ->width(400)
        ->height(400)
        ->background(Background::predominant())
    );

echo '******Your transformed image******';
echo '<br>';
echo $imgtag;
// The code above generates an HTML image tag similar to the following:
//  <img src="https://res.cloudinary.com/demo/image/upload/b_auto:predominant,c_pad,h_400,w_400/flower_sample">
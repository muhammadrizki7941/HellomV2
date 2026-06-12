<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$settings = \App\Models\LandingPageSetting::first();

if ($settings) {
    echo "Landing page settings found:\n";
    echo "Hero title: " . $settings->hero_title . "\n";
    echo "Hero subtitle: " . $settings->hero_subtitle . "\n";
    echo "Features count: " . count($settings->features ?? []) . "\n";
    echo "Pricing plans count: " . count($settings->pricing_plans ?? []) . "\n";
    echo "Problems count: " . count($settings->problems ?? []) . "\n";
    echo "Comparison features count: " . count($settings->comparison_features ?? []) . "\n";
} else {
    echo "No landing page settings found\n";
}
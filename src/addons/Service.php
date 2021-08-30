<?php
namespace yeagan\addons;

class Service extends \think\Service
{
    public function boot()
    {
        $this->app->event->listen('HttpRun', function () {
            $this->app->middleware->add(MultiAddon::class);
        });
    }
}
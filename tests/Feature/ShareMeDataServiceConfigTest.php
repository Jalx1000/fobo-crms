<?php

use Webkul\Admin\Services\ShareMeDataService;

it('se construye aunque no haya API key de SMD configurada', function () {
    config(['services.sharemedata.api_key' => null]);

    expect(app(ShareMeDataService::class))->toBeInstanceOf(ShareMeDataService::class);
});

<?php

use Illuminate\Testing\TestResponse;

it('no retorna 500 al visualizar lead inexistente', function () {
    /** @var TestResponse $response */
    $response = $this->get('/admin/leads/view/99999999');

    expect($response->getStatusCode())->not()->toBe(500);
});

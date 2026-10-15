<?php

it('genera URLs https detrás del proxy de Railway', function () {
    $this->get('/admin/login', [
        'X-Forwarded-For'   => '10.0.0.1',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host'  => 'tessa.example.com',
        'X-Forwarded-Port'  => '443',
    ])->assertOk()
        ->assertSee('https://tessa.example.com/admin/build/', false)
        ->assertDontSee('http://tessa.example.com/admin/build/', false);
});

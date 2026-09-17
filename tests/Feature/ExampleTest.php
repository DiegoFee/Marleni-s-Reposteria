<?php

test('guests are redirected to login from the application root', function () {
    $response = $this->get('/');

    $response->assertRedirectToRoute('login');
});

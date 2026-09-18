<?php

test('public email verification routes are disabled', function () {
    $this->get('/verify-email')->assertNotFound();
    $this->get('/verify-email/1/invalid-hash')->assertNotFound();
});

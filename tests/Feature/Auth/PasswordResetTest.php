<?php

test('public password recovery routes are disabled', function () {
    $this->get('/forgot-password')->assertNotFound();
    $this->get('/reset-password/test-token')->assertNotFound();
});

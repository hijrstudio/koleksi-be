<?php

test('guests are redirected to the sign in page', function () {
    $this->get('/')->assertRedirect(route('signin'));
});

test('the sign in page is displayed', function () {
    $this->get(route('signin'))->assertOk();
});

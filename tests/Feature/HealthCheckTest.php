<?php

test('health check reports ok without authentication', function () {
    $this->get(route('health'))
        ->assertOk()
        ->assertJson(['status' => 'ok'])
        ->assertJsonMissing(['database', 'password', 'secret', 'trace']);
});

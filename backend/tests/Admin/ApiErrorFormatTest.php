<?php

namespace Tests\Admin;

/** P2-5: every API error uses the { success, message, data, error } envelope. */
class ApiErrorFormatTest extends AdminTestCase
{
    public function test_not_found_and_validation_errors_use_the_envelope(): void
    {
        $admin = $this->superAdmin();

        $this->api($admin, 'GET', '/admin/product-purchases/999999999')->assertNotFound()
            ->assertJsonPath('success', false)->assertJsonPath('error.code', 'NOT_FOUND')->assertJsonPath('data', null);

        $this->api($admin, 'POST', '/admin/plans', [])->assertStatus(422)
            ->assertJsonPath('success', false)->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['slug', 'name', 'type', 'price']);

        $this->flushHeaders()->getJson('/api/v1/hellom/route-yang-tidak-ada')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }
}

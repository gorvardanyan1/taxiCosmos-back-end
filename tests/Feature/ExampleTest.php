<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The site root sends visitors to the admin panel (the public site is out of scope).
     */
    public function test_the_root_redirects_to_the_admin_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }
}

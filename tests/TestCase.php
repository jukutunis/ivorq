<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Reset only the flash-tracking keys between tests. ageFlashData() uses
        // _flash.old (a list of session keys to age-out) to delete stale data on
        // the NEXT save(). When _flash.old still contains 'errors' from a prior
        // test, ageFlashData() silently deletes the current test's withErrors()
        // data before assertSessionHasErrors() can read it.
        // Clearing _flash.old and _flash.new prevents this cross-test contamination
        // without invalidating the session or disrupting isStarted() state.
        $session = app('session.store');
        $session->put('_flash.old', []);
        $session->put('_flash.new', []);
        $session->forget('errors');
    }
}

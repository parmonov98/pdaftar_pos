<?php

use Pos\Providers\PosServiceProvider;

/*
 * pDaftar's own AppServiceProvider is deliberately NOT listed here.
 *
 * It boots Filament panels, Telescope, Horizon dashboards, Livewire, SMS
 * schedules and a dozen other things a till has no business running — and any
 * one of them failing would take the POS down with it. PosServiceProvider
 * registers only the parts of pDaftar's boot sequence that a sale actually
 * depends on, and asserts at boot that they are present.
 */
return [
    PosServiceProvider::class,
];

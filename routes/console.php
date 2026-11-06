<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Crea avisos para los productos que ya están en alerta y que el usuario aún no tiene
Artisan::command('notificaciones:sincronizar-stock', function () {
    $creadas = \App\Services\NotificacionService::sincronizarStock();
    $this->info("{$creadas} notificación(es) creada(s)");
})->purpose('Genera notificaciones de stock bajo para las alertas actuales');

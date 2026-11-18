<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Agendamentos — na Hostinger, um único cron a cada minuto:
|   * * * * * /opt/alt/php83/usr/bin/php /home/USUARIO/domains/DOMINIO/loja/artisan schedule:run
|
| A hospedagem compartilhada desliga proc_open/exec: o agendador NÃO pode abrir outro processo PHP
| (Schedule::command). Por isso cada tarefa roda dentro do próprio processo do cron (Artisan::call).
*/

$task = fn (string $command, array $options = []) => fn () => Artisan::call($command, $options);

// Sinal de vida do agendador (tela Saúde da loja avisa se o cron parar) — primeiro, para nunca atrasar
Schedule::call(fn () => \App\Support\SystemHealth::beat())->everyMinute()->name('health-beat');

// Cancela PIX vencidos / cartões recusados abandonados e devolve o estoque
Schedule::call($task('orders:expire'))->everyFiveMinutes()->name('orders-expire')->withoutOverlapping();

// Eventos server-side pendentes (Meta CAPI, TikTok) em lote, com retentativa
Schedule::call($task('tracking:flush'))->everyMinute()->name('tracking-flush')->withoutOverlapping();

// Lembrete de PIX não pago e recuperação de carrinho abandonado
Schedule::call($task('emails:reminders'))->everyTenMinutes()->name('emails-reminders')->withoutOverlapping();

// Feed de produtos para Meta/Google/TikTok (estoque e preço atualizados)
Schedule::call($task('feeds:build'))->everyThirtyMinutes()->name('feeds-build')->withoutOverlapping();

// Fila (e-mails, Meta CAPI) sem worker permanente: processa o que houver e para — por último, pois pode durar até 40s
Schedule::call($task('queue:work', ['--stop-when-empty' => true, '--max-time' => 40, '--tries' => 3]))
    ->everyMinute()->name('queue-work')->withoutOverlapping();

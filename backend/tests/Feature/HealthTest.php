<?php

declare(strict_types=1);

// Fase 0 DoD: the /up health endpoint answers 200 (architecture, section 14).
it('exposes the health check endpoint', function () {
    $this->get('/up')->assertOk();
});

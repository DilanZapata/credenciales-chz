<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\AuditRepository;
use App\Services\AuthContext;
use App\Services\AuthorizationService;

/** Consulta de la auditoria y de los eventos de seguridad (art. 8). */
final class AuditController extends Controller
{
    public function __construct(
        private AuditRepository $audit,
        private AuthorizationService $gate,
        private AuthContext $context
    ) {
    }

    public function resolveEvent(Request $request, array $params): Response
    {
        $this->gate->require('security.events.view');
        $this->audit->resolveSecurityEvent((int) $params['id'], (int) $this->context->id());
        $this->success('Evento marcado como resuelto.');
        return $this->redirect('/seguridad/eventos');
    }}

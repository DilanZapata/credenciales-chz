<?php
declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Services\UserService;

/** Administracion de usuarios (art. 2, 3, 10 y 19). */
final class UserController extends Controller
{
    private const STATUSES = ['active', 'inactive', 'locked', 'suspended'];

    public function __construct(
        private UserService $users
    ) {
    }

    public function store(Request $request): Response
    {
        $data    = $this->validatePayload($request);
        $roleIds = $request->arrayOfInts('roles');
        $result  = $this->users->create($data, $roleIds);

        // La clave temporal se muestra UNA sola vez al administrador.
        $this->flash('temporary_password', $result['temporary_password']);
        $this->success('Usuario creado. Entregue la contrasena temporal por un canal seguro.');
        return $this->redirect('/usuarios/' . $result['id']);
    }

    public function update(Request $request, array $params): Response
    {
        $id      = (int) $params['id'];
        $data    = $this->validatePayload($request, $id);
        $roleIds = $request->arrayOfInts('roles');
        $this->users->update($id, $data, $roleIds === [] ? null : $roleIds);
        $this->success('Usuario actualizado.');
        return $this->redirect('/usuarios/' . $id);
    }

    public function permissions(Request $request, array $params): Response
    {
        $id        = (int) $params['id'];
        $overrides = [];
        foreach ($request->arrayOfStrings('allow') as $code) {
            $overrides[$code] = 'allow';
        }
        foreach ($request->arrayOfStrings('deny') as $code) {
            $overrides[$code] = 'deny';
        }
        $this->users->setPermissionOverrides($id, $overrides);
        $this->success('Excepciones de permisos actualizadas.');
        return $this->redirect('/usuarios/' . $id);
    }

    public function deactivate(Request $request, array $params): Response
    {
        $id     = (int) $params['id'];
        $result = $this->users->deactivate(
            $id,
            $request->string('reason') ?: 'Baja del empleado',
            $request->int('reassign_to') ?: null
        );
        $this->success(sprintf(
            'Usuario desactivado. Accesos revocados: %d. Reasignados: %d. Sesiones cerradas: %d.',
            $result['revoked'], $result['reassigned'], $result['sessions_closed']
        ));
        return $this->redirect('/usuarios/' . $id);
    }

    public function reactivate(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $this->users->reactivate($id);
        $this->success('Usuario reactivado. Revise si debe restituir sus accesos.');
        return $this->redirect('/usuarios/' . $id);
    }

    public function resetPassword(Request $request, array $params): Response
    {
        $id    = (int) $params['id'];
        $plain = $this->users->resetPassword($id);
        $this->flash('temporary_password', $plain);
        $this->success('Contrasena restablecida. Entreguela por un canal seguro; el usuario debera cambiarla al ingresar.');
        return $this->redirect('/usuarios/' . $id);
    }

    private function validatePayload(Request $request, ?int $id = null): array
    {
        $validator = Validator::make($request->all())
            ->nationalId('national_id', 'La cedula')
            ->username('username', 'El nombre de usuario')
            ->email('email', 'El correo electronico')
            ->string('first_name', 'El nombre', 2, 80)
            ->string('last_name', 'El apellido', 2, 80)
            ->string('employee_code', 'El codigo de empleado', 0, 40, false)
            ->phone('phone', 'El telefono', false)
            ->string('position', 'El cargo', 0, 120, false)
            ->integer('company_id', 'La empresa', 1, null, false)
            ->integer('location_id', 'La sede', 1, null, false)
            ->integer('department_id', 'El departamento', 1, null, false)
            ->in('status', 'El estado', self::STATUSES, false)
            ->text('notes', 'Las notas', 500, false)
            ->bool('mfa_enforced');

        $clean = $validator->validated();
        $clean['mfa_enforced'] = $clean['mfa_enforced'] ? 1 : 0;
        if ($clean['status'] === null) {
            unset($clean['status']);
        }
        return $clean;
    }
}

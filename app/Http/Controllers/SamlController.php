<?php

namespace App\Http\Controllers;

use App\Services\SamlService;
use App\Services\SamlUserResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SamlController extends Controller
{
    public function login(Request $request, SamlService $service)
    {
        $auth = $service->auth();
        $url = $auth->login(url('/'), [], false, false, true);
        $request->session()->put('saml.login_request', ['id' => $auth->getLastRequestID(), 'at' => time()]);

        return redirect()->away($url);
    }

    public function acs(Request $request, SamlService $service, SamlUserResolver $users)
    {
        $pending = $request->session()->pull('saml.login_request');
        abort_unless($pending && time() - $pending['at'] <= 300, 403, 'A current SP login request is required.');
        try {
            $auth = $service->validateResponse($pending['id']);
            // Cache::add is atomic on the database store; IDs cannot be consumed twice.
            foreach ([$auth->getLastMessageId(), $auth->getLastAssertionId()] as $id) {
                if (! $id || ! Cache::add('saml:replay:'.hash('sha256', config('saml.idp.entity_id').$id), true, now()->addDay())) {
                    throw new \RuntimeException('SAML response was already consumed or has no identifier.');
                }
            }
            $user = $users->resolve($auth->getNameId(), $auth->getAttributes());
            Auth::login($user);
            $request->session()->regenerate();
            $request->session()->put('saml.identity', [
                'name_id' => $auth->getNameId(),
                'name_id_format' => $auth->getNameIdFormat(),
                'name_id_name_qualifier' => $auth->getNameIdNameQualifier(),
                'name_id_sp_name_qualifier' => $auth->getNameIdSPNameQualifier(),
                'session_index' => $auth->getSessionIndex(),
                'attributes' => $auth->getAttributes(),
            ]);

            // RelayState supplied by a caller is deliberately not used for redirects.
            return redirect('/');
        } catch (Throwable $e) {
            Log::warning('SAML login rejected', ['reason' => $e->getMessage()]);
            abort(403, 'SAML login rejected. Check configuration and the Laravel log.');
        }
    }

    public function metadata(SamlService $service)
    {
        return response($service->metadata(), 200, ['Content-Type' => 'application/samlmetadata+xml']);
    }

    public function logout(Request $request, SamlService $service)
    {
        $identity = $request->session()->get('saml.identity');
        abort_unless(Auth::check() && $identity, 403);
        $auth = $service->auth();
        $url = $auth->logout(url('/'), [], $identity['name_id'], $identity['session_index'], true,
            $identity['name_id_format'], $identity['name_id_name_qualifier'], $identity['name_id_sp_name_qualifier']);
        $request->session()->put('saml.logout_request', ['id' => $auth->getLastRequestID(), 'at' => time()]);

        return redirect()->away($url);
    }

    public function sls(Request $request, SamlService $service)
    {
        // This SP advertises HTTP-Redirect SLO. Reject unsupported POST binding explicitly.
        abort_unless($request->isMethod('GET'), 405, 'SLO uses HTTP-Redirect binding.');
        $pending = $request->session()->get('saml.logout_request');
        $isResponse = $request->has('SAMLResponse');
        abort_unless($request->has('SAMLRequest') || $isResponse, 400);
        if ($isResponse) {
            abort_unless($pending && time() - $pending['at'] <= 300, 403);
        }
        try {
            $auth = $service->auth();
            $url = $auth->processSLO(true, $isResponse ? $pending['id'] : null, false, null, true);
            if ($auth->getErrors() !== []) {
                throw new \RuntimeException('SAML logout validation failed: '.implode(', ', $auth->getErrors()));
            }
            $service->validateLogoutProfile($auth, $isResponse ? $pending['id'] : null, $request->session()->get('saml.identity'));
            if (! $isResponse) {
                $id = $auth->getLastMessageId();
                if (! $id || ! Cache::add('saml:slo:'.hash('sha256', config('saml.idp.entity_id').$id), true, now()->addDay())) {
                    throw new \RuntimeException('Duplicate logout request.');
                }
            }
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // A response to an IdP request is directed only to the configured IdP.
            return $url ? redirect()->away($url) : redirect('/');
        } catch (Throwable $e) {
            Log::warning('SAML logout rejected', ['reason' => $e->getMessage()]);
            abort(403, 'SAML logout rejected.');
        }
    }
}

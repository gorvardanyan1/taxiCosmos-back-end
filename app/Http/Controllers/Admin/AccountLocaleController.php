<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateLocaleRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * An admin chooses the language of the admin panel (My Account → Language). The page then reloads in
 * full, so the browser loads the new dictionary; the confirmation is shown in the new language.
 */
class AccountLocaleController extends Controller
{
    public function __invoke(UpdateLocaleRequest $request, AuditLogger $audit): Response
    {
        /** @var User $admin */
        $admin = $request->user();
        $previous = $admin->locale;
        $locale = $request->validated('locale');

        if ($previous !== $locale) {
            $admin->forceFill(['locale' => $locale])->save();
            $audit->record(actor: $admin, action: 'account.locale_changed', target: $admin, old: ['locale' => $previous], new: ['locale' => $locale]);
        }

        App::setLocale($locale);
        session()->flash('success', __('Language updated.'));

        return Inertia::location(route('admin.account', absolute: false));
    }
}

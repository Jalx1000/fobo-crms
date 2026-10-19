<?php

namespace Webkul\Admin\Http\Controllers\User;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Core\Menu\MenuItem;

class SessionController extends Controller
{
    /**
     * Show the form for creating a new resource.
     */
    public function create(): RedirectResponse|View
    {
        if (auth()->guard('user')->check()) {
            return $this->redirectToHome();
        }

        $previousUrl = url()->previous();

        $intendedUrl = str_contains($previousUrl, 'admin')
            ? $previousUrl
            : route('admin.dashboard.index');

        session()->put('url.intended', $intendedUrl);

        return view('admin::sessions.login');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse
    {
        $this->validate(request(), [
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (! auth()->guard('user')->attempt(request(['email', 'password']), request('remember'))) {
            session()->flash('error', trans('admin::app.users.login-error'));

            return redirect()->back();
        }

        if (auth()->guard('user')->user()->status == 0) {
            session()->flash('warning', trans('admin::app.users.activate-warning'));

            auth()->guard('user')->logout();

            return redirect()->route('admin.session.create');
        }

        if (! bouncer()->hasPermission('dashboard')) {
            return $this->redirectToHome();
        }

        $menus = menu()->getItems('admin');

        $availableNextMenu = $menus?->first();

        $hasAccessToIntendedUrl = $this->canAccessIntendedUrl($menus, redirect()->getIntendedUrl());

        if ($hasAccessToIntendedUrl) {
            return redirect()->intended(route('admin.dashboard.index'));
        }

        return redirect()->to($availableNextMenu->getUrl());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(): RedirectResponse
    {
        auth()->guard('user')->logout();

        return redirect()->route('admin.session.create');
    }

    /**
     * Where a logged-in user lands: the dashboard if their role allows it,
     * otherwise the first menu they can see. Sending a role without the
     * dashboard permission there ends in a 401 on every visit to the site.
     */
    protected function redirectToHome(): RedirectResponse
    {
        if (bouncer()->hasPermission('dashboard')) {
            return redirect()->route('admin.dashboard.index');
        }

        $availableNextMenu = menu()->getItems('admin')?->first();

        if (is_null($availableNextMenu)) {
            session()->flash('error', trans('admin::app.users.not-permission'));

            auth()->guard('user')->logout();

            return redirect()->route('admin.session.create');
        }

        return redirect()->to($availableNextMenu->getUrl());
    }

    /**
     * Find menu item by URL.
     */
    protected function canAccessIntendedUrl(Collection $menus, ?string $url): ?MenuItem
    {
        if (is_null($url)) {
            return null;
        }

        foreach ($menus as $menu) {
            if ($menu->getUrl() === $url) {
                return $menu;
            }

            if ($menu->haveChildren()) {
                $found = $this->canAccessIntendedUrl($menu->getChildren(), $url);

                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }
}

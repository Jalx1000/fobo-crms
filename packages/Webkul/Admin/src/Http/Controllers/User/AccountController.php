<?php

namespace Webkul\Admin\Http\Controllers\User;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Webkul\Admin\Http\Controllers\Controller;

class AccountController extends Controller
{
    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\View\View
     */
    public function edit()
    {
        $user = auth()->guard('user')->user();

        return view('admin::user.account.edit', compact('user'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function update()
    {
        $user = auth()->guard('user')->user();

        $this->validate(request(), [
            'name'             => 'required',
            'email'            => 'email|unique:users,email,'.$user->id,
            'password'         => 'nullable|min:6|confirmed',
            'current_password' => 'required|min:6',
            'image.*'          => 'nullable|mimes:bmp,jpeg,jpg,png,webp',
            'cargo'            => 'nullable|string|max:120',
            'firma.*'          => 'nullable|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        $data = request()->only([
            'name',
            'cargo',
            'email',
            'password',
            'password_confirmation',
            'current_password',
            'image',
            'firma',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            session()->flash('warning', trans('admin::app.account.edit.invalid-password'));

            return redirect()->back();
        }

        if (isset($data['role_id']) || isset($data['view_permission'])) {
            session()->flash('warning', trans('admin::app.user.account.permission-denied'));

            return redirect()->back();
        }

        $isPasswordChanged = false;

        if (! $data['password']) {
            unset($data['password']);
        } else {
            $isPasswordChanged = true;

            $data['password'] = bcrypt($data['password']);
        }

        if (request()->hasFile('image')) {
            $data['image'] = current(request()->file('image'))->store('admins/'.$user->id);
        } else {
            if (! isset($data['image'])) {
                if (! empty($data['image'])) {
                    Storage::delete($user->image);
                }

                $data['image'] = null;
            } else {
                $data['image'] = $user->image;
            }
        }

        /*
        | La firma sigue la misma lógica de tres estados que el avatar:
        | archivo nuevo → se reemplaza; campo ausente → se borra (el asesor la
        | quitó desde el componente); campo presente sin archivo → se conserva.
        */
        if (request()->hasFile('firma')) {
            if ($user->firma) {
                Storage::delete($user->firma);
            }

            $data['firma'] = current(request()->file('firma'))->store('admins/'.$user->id.'/firma');
        } elseif (! isset($data['firma'])) {
            if ($user->firma) {
                Storage::delete($user->firma);
            }

            $data['firma'] = null;
        } else {
            $data['firma'] = $user->firma;
        }

        $user->update($data);

        if ($isPasswordChanged) {
            Event::dispatch('user.account.update-password', $user);
        }

        session()->flash('success', trans('admin::app.account.edit.update-success'));

        return back();
    }
}

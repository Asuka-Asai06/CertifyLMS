<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAvatarRequest;
use App\Http\Requests\Settings\UpdateProfileRequest;
use App\Models\User;
use App\UseCases\Settings\DestroyAvatarAction;
use App\UseCases\Settings\StoreAvatarAction;
use App\UseCases\Settings\UpdateProfileAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * プロフィール関連の操作を扱う Controller。
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings.profile', [
            'user' => $request->user(),
        ]);
    }

    public function update(UpdateProfileRequest $request, UpdateProfileAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action($user, $request->validated());

        return redirect()
            ->route('settings.profile.edit')
            ->with('success', 'プロフィールを更新しました。');
    }

    public function storeAvatar(StoreAvatarRequest $request, StoreAvatarAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action($user, $request->file('avatar'));

        return redirect()
            ->route('settings.profile.edit')
            ->with('success', 'アバター画像を更新しました。');
    }

    public function destroyAvatar(Request $request, DestroyAvatarAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action($user);

        return redirect()
            ->route('settings.profile.edit')
            ->with('success', 'アバター画像を削除しました。');
    }
}

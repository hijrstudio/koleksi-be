<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateMemberProfileRequest;
use App\Http\Resources\MemberEditProfileResource;
use App\Services\MemberProfileService;
use Illuminate\Http\Request;

/** Lihat & ubah profil oleh member yang sedang login. */
class ProfileController extends Controller
{
    public function show(Request $request): MemberEditProfileResource
    {
        return new MemberEditProfileResource($request->user());
    }

    public function update(UpdateMemberProfileRequest $request, MemberProfileService $profiles): MemberEditProfileResource
    {
        $member = $profiles->update(
            $request->member(),
            $request->validated(),
            $request->boolean('has_vehicle') ? $request->file('documents', []) : [],
            $request->removeDocumentIds(),
            $request->file('photo'),
            $request->boolean('remove_photo'),
        );

        return new MemberEditProfileResource($member);
    }
}

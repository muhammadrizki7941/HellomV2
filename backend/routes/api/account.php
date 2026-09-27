<?php

/*
| Authenticated account: profile, organizations, team & invitations.
| Loaded from routes/api.php inside the v1/hellom prefix group.
*/

use App\Http\Controllers\Api\V1\Hellom\AuthController;
use App\Http\Controllers\Api\V1\Hellom\OrganizationController;
use App\Http\Controllers\Api\V1\Hellom\OrganizationTeamController;
use App\Http\Controllers\Api\V1\Hellom\RealtimeController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
Route::put('/auth/profile', [AuthController::class, 'updateProfile'])->name('auth.profile.update');
Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->name('auth.change_password');
Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
Route::get('/organizations/current', [OrganizationController::class, 'current'])->name('organizations.current');
Route::post('/organizations/switch', [OrganizationController::class, 'switch'])->name('organizations.switch');
Route::get('/organizations/current/settings', [OrganizationController::class, 'settings'])->name('organizations.settings');
Route::post('/organizations/current/settings', [OrganizationController::class, 'updateSettings'])->name('organizations.updateSettings');
Route::get('/organizations/current/team', [OrganizationTeamController::class, 'index'])->name('organizations.current.team.index');
Route::post('/organizations/current/team/invite', [OrganizationTeamController::class, 'invite'])->name('organizations.current.team.invite');
Route::get('/organizations/current/team/invitations', [OrganizationTeamController::class, 'listInvitations'])->name('organizations.current.team.invitations.index');
Route::post('/organizations/current/team/invitations', [OrganizationTeamController::class, 'inviteByToken'])->name('organizations.current.team.invitations.store');
Route::post('/organizations/current/team/invitations/bulk-revoke', [OrganizationTeamController::class, 'bulkRevokeInvitations'])->name('organizations.current.team.invitations.bulk_revoke');
Route::post('/organizations/current/team/invitations/bulk-resend', [OrganizationTeamController::class, 'bulkResendInvitations'])->name('organizations.current.team.invitations.bulk_resend');
Route::get('/organizations/current/team/invitations/{invitationId}', [OrganizationTeamController::class, 'showInvitation'])->name('organizations.current.team.invitations.show');
Route::post('/organizations/current/team/invitations/{invitationId}/resend', [OrganizationTeamController::class, 'resendInvitation'])->name('organizations.current.team.invitations.resend');
Route::delete('/organizations/current/team/invitations/{invitationId}', [OrganizationTeamController::class, 'revokeInvitation'])->name('organizations.current.team.invitations.destroy');
Route::post('/organizations/current/team/invitations/accept', [OrganizationTeamController::class, 'acceptInvitation'])->name('organizations.current.team.invitations.accept');
Route::put('/organizations/current/team/{userId}/role', [OrganizationTeamController::class, 'updateRole'])->name('organizations.current.team.update_role');
Route::delete('/organizations/current/team/{userId}', [OrganizationTeamController::class, 'destroy'])->name('organizations.current.team.destroy');

// Socket.IO handshake token (private rooms: user_<id>, admins for super admins)
Route::get('/realtime/token', [RealtimeController::class, 'token'])->name('realtime.token');

<?php

/*
 * This file is part of SeAT
 *
 * Copyright (C) 2015, 2016, 2017, 2018, 2019  Leon Jacobs
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace Seat\Web\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Services\Models\UserSetting;
use Seat\Services\Settings\Profile;
use Seat\Web\Acl\AccessChecker;
use Seat\Web\Models\Acl\Role;
use Seat\Web\Models\Squads\Squad;

/**
 * Class User.
 * @package Seat\Web\Models
 *
 * @SWG\Definition(
 *     description="User",
 *     title="User",
 *     type="object"
 * )
 *
 * @SWG\Property(
 *     type="integer",
 *     format="int64",
 *     minimum=90000000,
 *     description="ID",
 *     property="id"
 * )
 *
 * @SWG\Property(
 *     type="string",
 *     description="Name",
 *     maxLength=255,
 *     property="name"
 * )
 *
 * @SWG\Property(
 *     type="string",
 *     format="email",
 *     description="E-Mail address",
 *     property="email"
 * )
 *
 * @SWG\Property(
 *     type="boolean",
 *     description="Account status",
 *     property="active"
 * )
 *
 * @SWG\Property(
 *     type="string",
 *     description="Unique character/EVE Account hash",
 *     maxLength=255,
 *     property="character_owner_hash"
 * )
 *
 * @SWG\Property(
 *     type="string",
 *     format="date-time",
 *     description="Last login to SeAT time",
 *     property="last_login"
 * )
 *
 * @SWG\Property(
 *     type="string",
 *     description="Last IP address used to sign in to SeAT",
 *     property="last_login_source"
 * )
 *
 * @SWG\Property(
 *     type="integer",
 *     minimum=1,
 *     description="Group ID",
 *     property="group_id"
 * )
 *
 * @SWG\Property(
 *     type="array",
 *     description="Array of attached character ID",
 *     property="associated_character_ids",
 *     @SWG\Items(type="integer", format="int64", minimum=90000000)
 * )
 *
 * @SWG\Property(
 *     type="integer",
 *     format="int64",
 *     minimum=90000000,
 *     description="The main character ID of this group",
 *     property="main_character_id"
 * )
 *
 * @SWG\Property(
 *     property="token",
 *     ref="#/definitions/RefreshToken"
 * )
 */
class User extends Model implements AuthenticatableContract, CanResetPasswordContract
{
    use Authenticatable, CanResetPassword, AccessChecker, Notifiable;

    /**
     * @var bool
     */
    public $incrementing = true;

    /**
     * @var array
     */
    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'main_character_id', 'active',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * The attributes included in model's JSON form.
     *
     * @var array
     */
    protected $appends = ['email'];

    /**
     * Make sure we cleanup on delete.
     *
     * @return bool|null
     * @throws \Exception
     */
    public function delete()
    {

        // Cleanup the user
        $this->login_history()->delete();
        $this->refresh_tokens()->delete();
        $this->settings()->delete();

        return parent::delete();
    }

    /**
     * Users have a login history.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function login_history()
    {

        return $this->hasMany(UserLoginHistory::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function refresh_tokens()
    {
        return $this->hasMany(RefreshToken::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function settings()
    {

        return $this->hasMany(UserSetting::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function characters()
    {

        return $this->hasManyThrough(CharacterInfo::class, RefreshToken::class, 'user_id', 'character_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function main_character()
    {
        return $this->hasOne(CharacterInfo::class, 'character_id', 'main_character_id')
            ->withDefault();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function squads()
    {
        return $this->belongsToMany(Squad::class, 'squad_member')
            ->withPivot('created_at');
    }

    /**
     * Return the email address for this user based on the
     * email address setting.
     *
     * @return mixed
     * @throws \Seat\Services\Exceptions\SettingException
     */
    public function getEmailAttribute()
    {

        return Profile::get('email_address', $this->id) ?: '';
    }

    /**
     * Return the character ID's this user is associated with as a
     * result of common group memberships.
     *
     * These are basically the characters the same account has logged
     * in with using the "link another" button.
     *
     * @return \Illuminate\Support\Collection
     */
    public function associatedCharacterIds()
    {
        return $this->characters->pluck('character_id')->flatten();
    }
}

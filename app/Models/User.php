<?php

namespace App\Models;

use Illuminate\Auth\GenericUser;

/**
 * 中央账号在 RPG 会话中的只读身份快照。
 *
 * 该对象不连接数据库；账号与权限的唯一来源仍是 next-api。
 */
class User extends GenericUser
{
    /**
     * @param  array{id:int,name:string,email?:string|null,is_admin?:bool,permissions?:array<int,string>}  $attributes
     */
    public function __construct(array $attributes)
    {
        parent::__construct([
            'id' => (int) $attributes['id'],
            'name' => (string) $attributes['name'],
            'email' => $attributes['email'] ?? null,
            'is_admin' => (bool) ($attributes['is_admin'] ?? false),
            'permissions' => array_values(array_unique(array_map('strval', $attributes['permissions'] ?? []))),
        ]);
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function hasRole(string $role): bool
    {
        return $role === 'admin' && $this->isAdmin();
    }

    public function can(string $ability, $arguments = []): bool
    {
        return $this->isAdmin() || in_array($ability, $this->permissions, true);
    }

    /** @return array{id:int,name:string,email:string|null,is_admin:bool,permissions:array<int,string>} */
    public function toArray(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'email' => $this->email !== null ? (string) $this->email : null,
            'is_admin' => $this->isAdmin(),
            'permissions' => $this->permissions,
        ];
    }
}

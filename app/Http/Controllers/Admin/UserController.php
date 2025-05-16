<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'role'  => 'nullable|string',
            'search'=> 'nullable|string|max:100',
            'sort'  => 'nullable|in:artworks,collections,created',
            'dir'   => 'nullable|in:asc,desc',
            'page'  => 'nullable|integer',
        ]);

        $q = User::query()
            ->withCount(['artworks','collections'])
            ->with('roles:id,name');

        if ($filters['role'] ?? false) {
            $q->whereHas('roles', fn($r)=>$r->where('name',$filters['role']));
        }
        if ($filters['search'] ?? false) {
            $s = $filters['search'];
            $q->where('name','like',"%$s%")->orWhere('email','like',"%$s%");
        }

        $sort = $filters['sort'] ?? 'created';
        $dir  = $filters['dir']  ?? 'desc';
        $orderMap = [
            'artworks'    => 'artworks_count',
            'collections' => 'collections_count',
            'created'     => 'created_at',
        ];
        $q->orderBy($orderMap[$sort], $dir);

        $users = $q->paginate(12)->withQueryString();

        return Inertia::render('Admin/UsersManager', [
            'users'   => $users,
            'roles'   => Role::pluck('name'),
            'filters' => [
                'role'  => $filters['role']  ?? '',
                'search'=> $filters['search']?? '',
                'sort'  => $sort,
                'dir'   => $dir,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'roles'    => ['required','array'],
            'roles.*'  => [Rule::exists('roles','name')],
            ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        $user->syncRoles($data['roles']);

        return response()->json(['message'=>'User created','user'=>$user->load('roles')]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => ['required','email', Rule::unique('users','email')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
            'roles'    => ['required','array'],
            'roles.*'  => [Rule::exists('roles','name')],
            ]);

        $user->name  = $data['name'];
        $user->email = $data['email'];
        if (!empty($data['password'])) $user->password = Hash::make($data['password']);
        $user->save();
        $user->syncRoles($data['roles']);

        return response()->json(['message'=>'User updated']);
    }

    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(['message'=>'User deleted']);
    }
}

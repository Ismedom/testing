<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UserAuth extends Controller
{
    public function login(Request $request){
        try{

            $credentials = $request->validate([ 'email' => 'required|email',
                'password' => 'required'
            ]);


            if (Auth::attempt($credentials)) {
                $token = $request->user()->createToken('web')->plainTextToken;
                
                return response()->json([
                    'user' => $token
                ]);
            }
        
            return response()->json(['credentials'=>  $credentials]);

        }catch (ValidationException $e){
            return response()->json(['error'=>"something when wrong"]);
        }
    }


    // 
    public function register(Request $request){
        try{
            $validator = Validator::make($request->all(),[
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|unique:users,email',
                'password' => 'required|string'
            ]);
            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password
            ]);
            
            $token = $user->createToken('web')->plainTextToken;

            return response()->json(['credentials'=> $user,
            'token' => $token
        ]);

        }catch (ValidationException $e){
            return response()->json(['error'=>$e]);
        }
    }
}
<?php

namespace App\Http\Controllers\Auth;

use App\BusinessLogicLayer\CrowdSourcingProject\CrowdSourcingProjectManager;
use App\BusinessLogicLayer\UserManager;
use App\BusinessLogicLayer\UserRoleManager;
use App\Http\Controllers\Controller;
use App\Notifications\UserRegistered;
use App\Repository\Questionnaire\Responses\QuestionnaireResponseRepository;
use App\Utils\MailChimpAdaptor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Auth\RegistersUsers;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    protected $redirectTo = '/en/my-dashboard';
    public function redirectTo()
    {
        return app()->getLocale() . '/my-dashboard';
    }

    private $userRoleManager;
    private $userManager;
    private $mailChimpManager;
    private $crowdSourcingProjectManager;
    protected $questionnaireResponseRepository;

    public function __construct(UserRoleManager $userRoleManager,
                                UserManager $userManager,
                                MailChimpAdaptor $mailChimpManager,
                                CrowdSourcingProjectManager $crowdSourcingProjectManager,
                                QuestionnaireResponseRepository   $questionnaireResponseRepository) {
        $this->middleware('guest');
        $this->userRoleManager = $userRoleManager;
        $this->userManager = $userManager;
        $this->mailChimpManager = $mailChimpManager;
        $this->crowdSourcingProjectManager = $crowdSourcingProjectManager;
        $this->questionnaireResponseRepository = $questionnaireResponseRepository;
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'nickname' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed'
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return \App\Models\User
     */
    protected function create(array $data) {
        $user = $this->userManager->createUser($data);
        $this->userRoleManager->assignRegisteredUserRoleTo($user);
        $user->notify(new UserRegistered());
        return $user;
    }

    protected function registered(Request $request, $user)
    {
        $this->questionnaireResponseRepository->transferQuestionnaireResponsesOfAnonymousUserToUser($user->id);
        $this->mailChimpManager->subscribe($user->email, 'registered_users',$user->nickname);
        //same code with Login controller authenticated method
        $url = session("redirectTo") ? session("redirectTo") : $this->redirectTo;
        return redirect($url)->withCookie(Cookie::forever(UserManager::$USER_COOKIE_KEY, $user->id));
    }
}

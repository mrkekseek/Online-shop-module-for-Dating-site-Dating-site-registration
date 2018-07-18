<?php

namespace App\Http\Controllers\gentleman;

use App\Gentlemen;
use App\Http\Controllers\Controller;
use App\User;
use App\UserQuestions;
use App\Countries;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Intervention\Image\ImageManagerStatic as Image;
use Storage;
use Validator;
use Illuminate\Validation\Rule;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use App\Mail\GentlRegistered;

use App\Events\NewGentlRegistered;
use App\Events\UserLoginEvent;

class RegistrationController extends Controller
{
    public function step2Get()
    {
        return redirect('');
    }

    public function step2Post($id = FALSE, $data = [])
    {
        $data = array_only($data, ['ques', 'ages_from', 'ages_to']);
        return ['data' => $data];
    }

    public function step3Get()
    {
        return redirect('');
    }

    public function step3Post($id = FALSE, $data = [])
    {
        $data = array_only($data, ['ques', 'ages_from', 'ages_to']);
        $countries = Countries::orderBy('order')->get();
        return compact('data', 'countries');
    }

    public function step3Ajax($id = FALSE, $data = [])
    {
        $validator = Validator::make($data, [
            'nickname' => [
                'required',
                'alpha',
                'regex:/(^([a-zA-z]+)(\d+)?$)/u'
            ],
            'fname' => 'required|alpha',
            'lname' => 'required|alpha',
            'dob' => 'required|date',
            'country_origin' => 'required',
            'city' => 'required',
            'email' => ['required', 'email', Rule::unique('users')->where(function ($q) {
                    $q->where('role', 3);
                })],
            'password' => 'required|regex:/(^([a-zA-z0-9]+)(\d+)?$)/u',
        ], [
            'nickname.required' => 'Please enter your Nickname<br>',
            'nickname.alpha' => 'You should enter only characters in Nickname field <br>',
            'nickname.regex' => 'You should enter only latin characters in Nickname field <br>',
            'nickname.unique' => 'You register Nickname already. Please select another.<br>',
            'fname.required' => 'Please enter your First Name<br>',
            'fname.alpha' => 'You should enter only characters in First Name field <br>',
            'fname.regex' => 'You should enter only latin characters in First Name field <br>',
            'lname.regex' => 'You should enter only latin characters in Last Name field <br>',
            'lname.required' => 'Please enter your Last Name<br>',
            'lname.alpha' => 'You should enter only characters in Last Name field <br>',
            'dob.required' => 'Please enter Birth Date<br>',
            'dob.date' => 'Please enter valid date<br>',
            'country_origin.required' => 'Please enter Location<br>',
            'city.required' => 'Please enter City<br>',
            'email.required' => 'Please enter Email<br>',
            'email.email' => 'Please enter valid Email<br>',
            'email.unique' => 'You register already. Please login to continue.<br>',
            'email.password' => 'Please enter Password<br>',
            'password.regex' => 'You should enter numbers or latin characters in Password field <br>',
            'promotion_option.required' => 'Please enter news events<br>'
        ]);

        if ($validator->fails()) {
            $result = [
                'success' => FALSE,
                'messages' => $validator->getMessageBag()->toArray(),
            ];
        } else {

            $user = new User;
            $user->email = $data['email'];
            $user->password = bcrypt($data['password']);
            $user->role = 3;
            $user->save();
            $user->attachRole(3);
    
            $gentlemen = new Gentlemen();
            $gentlemen->user_id = $user->id;
            $gentlemen->fname = $data['fname'];
            $gentlemen->lname = $data['lname'];
            $gentlemen->nickname = $data['nickname'];
            $gentlemen->dob = Carbon::parse($data['dob'])->format('Y-m-d');
            $gentlemen->email = $data['email'];
            $gentlemen->password = '';
            $gentlemen->phone = '';
            $gentlemen->address = '';
            $gentlemen->city = $data['city'];
            $gentlemen->country_origin = $data['country_origin'];
            $gentlemen->country_living = $data['country_origin'];
            $gentlemen->promotion_option = $data['promotion_option'] != "on" ? false : true;
            $gentlemen->zip = '';
            $gentlemen->date_of_reg = Carbon::now()->format('Y-m-d H:i:s');
            $gentlemen->featured = 0;
            $gentlemen->paid = 0;
            $gentlemen->status = 0;
            $gentlemen->save();
            
            $user->name = $user->generateGentlemanCode($gentlemen->id);
            $user->save();

            event(new NewGentlRegistered($user));

            event(new UserLoginEvent($user));

            $result = [
                'success' => TRUE,
                'messages' => '',
                '_id' => $user->id
            ];
        }
        return $result;
    }

    public function step4Post($id = FALSE, $data = [])
    {
        $looking = UserQuestions::where([
            'value' => 'Reasons for Joining the Website',
        ])->with('answers')->first();
        $data = array_only($data, ['ages_from', 'ages_to', 'fb_avatar', 'facebook', '_id', 'nickname', 'fname', 'lname', 'dob', 'country_origin', 'city', 'email', 'password', 'ques', 'promotion_option']);
        return compact('data', 'looking');
    }

    public function step4Ajax($id = FALSE, $data = [])
    {
        $validator = Validator::make($data, [
            'sques.31' => 'required',
            'sques.26' => 'required',
        ], [
            'sques.31.required' => 'Please choose travelling variant',
            'sques.26.required' => 'Please choose looking'
        ]);

        if ($validator->fails()) {
            $result = [
                'success' => FALSE,
                'messages' => $validator->getMessageBag()->toArray(),
            ];
        } else {
            $result = [
                'success' => TRUE,
                'messages' => '',
            ];
        }
        return $result;
    }

    public function step5Post($id = FALSE, $data = [])
    {
        $data = array_only($data, ['ages_from', 'ages_to', 'fb_avatar', 'facebook', '_id', 'nickname', 'fname', 'lname', 'dob', 'country_origin', 'city', 'email', 'password', 'ques', 'sques', 'promotion_option']);
        $data['sques'][31] = explode(',', $data['sques'][31]);
        return [
            'data' => $data,
        ];
    }

    public function step5Ajax($id = FALSE, $data = [])
    {
        $rule = [
            'bio' => 'required'
        ];

        $messages = [
            'bio.required' => 'Please write a self-description about yourself.'
        ];

        if ( ! empty($data['fb_avatar'])) {
            $rule['dp'] = 'image';
            $messages['dp.image'] = 'Please upload at least one photo.';
        }

        $validator = Validator::make($data, $rule, $messages);
        
        if ($validator->fails()) {
            $result = [
                'success' => FALSE,
                'messages' => $validator->getMessageBag()->toArray(),
            ];
        } else {
            $result = [
                'success' => TRUE,
                'messages' => '',
            ];
        }
        return $result;
    }

    public function step6Post($id = FALSE, $data = [])
    {
        $data = array_only($data, ['ages_from', 'ages_to', 'fb_avatar', 'facebook', '_id', 'nickname', 'fname', 'lname', 'bio', 'ques', 'sques', 'dob', 'country_origin', 'city', 'email', 'password', 'dp', 'promotion_option']);
        $arr_attr = ['Hair Color', 'Eye Color', 'My Size', 'Cut', 'Facial Hair', 'Body Hair'];
        $atributes = UserQuestions::whereIn('value', $arr_attr)->with('answers')->get();
        $atributes[5]->answers = $atributes[5]->answers->sortByDesc('name');
        if ( ! empty($data['dp'])) {

            $data['dp'] = $data['dp']->store('temp', 's3');
        }
        return [
            'data' => $data,
            'atributes' => $atributes,
        ];
    }

    public function step7Post($id = FALSE, $data = [])
    {
        $data = array_only($data, ['ages_from', 'ages_to', 'fb_avatar', 'facebook', '_id', 'nickname', 'fname', 'lname', 'bio', 'ques', 'sques', 'dob', 'country_origin', 'city', 'email', 'password', 'dp', 'height', 'weight', 'activity', 'relationships', 'dreamGuy', 'promotion_option']);
        $block1_ar = ['Ethnicity', 'Occupation'];
        $block2_ar = ['Education', 'Native Language', 'Second Language'];
        $blok1 = UserQuestions::whereIn('value', $block1_ar)->with('answers')->get();
        $blok2 = UserQuestions::whereIn('value', $block2_ar)->with('answers')->get();
        $countries = Countries::get();
        return [
            'data' => $data,
            'blok1' => $blok1,
            'blok2' => $blok2,
            'countries' => $countries
        ];
    }

    public function finishPost($id = FALSE, $data = [])
    {
        $data = array_only($data, ['ages_from', 'ages_to', 'fb_avatar', 'facebook', '_id', 'nickname', 'fname', 'lname', 'bio', 'ques', 'sques', 'dob', 'country_origin', 'city', 'email', 'password', 'dp', 'height', 'weight','pref_details', 'country_study', 'promotion_option']);   
        $user = User::find($data['_id']);

        if ( ! $user) {            
            return [
                'status' => FALSE,
                'message' => 'User not found!'
            ];
        }

        $gentlemen = $user->gentleman;

        if ( ! empty($data['facebook'])) {
            $user->facebook_user = true;
            
            if ( ! empty($data['fb_avatar'])) {
                $img = Image::make($data['fb_avatar'])->encode();
                $gentlemen->dp = 'uploads/' . md5(time()) . ".jpg";
                Storage::disk('s3')->put($gentlemen->dp, $img->__toString());
            }
        }
      
        if ( ! empty($data['dp']) && empty($data['fb_avatar']))
        {
            if (in_array($data['dp'], Storage::disk('s3')->files('temp')))
            {
                $public_url = config('service.s3.public_url');
                $filename = str_replace("temp/", "", $data['dp']);
                Storage::disk('s3')->move($data['dp'], "/uploads/" . $filename);
                $pathinfo = pathinfo(config('services.s3.public_url') . "/uploads/" . $filename);
                $ext = $pathinfo['extension'];
                $filename_thumb = str_replace('.' . $ext, '_thumb.' . $ext, $filename);
                
                $img = Image::make(config('services.s3.public_url') . "uploads/" . $filename)->resize(300);

                Storage::disk('s3')->put('uploads/' . $filename_thumb, $img->__toString());
                $gentlemen->dp = "uploads/" . $filename;
            }
            else
            {
                $gentlemen->dp = '';
            }
        }

        if (!empty ($data['activity'])) {$gentlemen->activity = $data['activity'];}
        if (!empty ($data['relationships'])) {$gentlemen->relationships = $data['relationships'];}
        if (!empty ($data['dreamGuy'])) {$gentlemen->dream_guy = $data['dreamGuy'];}
        
        $gentlemen->height = !empty($data['height']) ? $data['height'] : 0;
        $gentlemen->weight = !empty($data['weight']) ? $data['weight'] : 0;
        $gentlemen->about_me = $data['bio'];
        $gentlemen->country_study = $data['country_study'];

       
        $gentlemen->ages_from =  ! empty($data['ages_from']) ? $data['ages_from'] : 0;
        $gentlemen->ages_to =  ! empty($data['ages_to']) ? $data['ages_to'] : 0;

        $gentlemen->save();

        $pref_details = ! empty($data['pref_details']) ? $data['pref_details'] : '';
        $gentlemen->pref()->create([
            'details' => $pref_details,
        ]);
        $answers = [];
        foreach ($data['ques'] as $ques => $ans) {
            if (!empty($ans)) {
                $answers[$ans] = ['question_id' => $ques];
            }
        }
        foreach ($data['sques'] as $s_quest => $s_ans_ids) {
            foreach ($s_ans_ids as $ans) {
                $answers[$ans] = ['question_id' => $s_quest];
            }
        }
        $gentlemen->answers()->sync($answers);

        $user->save();

        Auth::login($user);

        return redirect('gentleman/overview');
    }

    public function tellMorePost($id = FALSE, $data = [])
    {
        $data = array_only($data, ['ages_from', 'ages_to', 'nickname', 'fname', 'lname', 'bio', 'ques', 'sques', 'dob', 'country_origin', 'city', 'email', 'password', 'dp', 'height', 'weight', 'country_study']);

        $names = ['Activities You Want to Do with Your Guy','Your Dating Ethnicity Preference'];
        $question = UserQuestions::whereIn('value', $names)->with('answers')->get();

        $looking = $question->where('value', 'Activities You Want to Do with Your Guy')->first();
        $preference = $question->where('value', 'Your Dating Ethnicity Preference')->first();

        return compact('data','looking','preference');
    }

    public function sendConfirmEmailAgainAjax($id = FALSE, $data = [])
    {
        $user = User::where('email', $data['email'])->first();
        if ($user) {

            event(new NewGentlRegistered($user));

            return [
                'success' => true,
                'messages' => 'Email confirmation send.'
            ];
        }

        return [
            'success' => false,
            'messages' => 'Something went wrong.'
        ];
    }
}
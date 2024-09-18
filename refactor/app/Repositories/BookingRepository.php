<?php

namespace DTApi\Repository;
use DTApi\Events\SessionEnded;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Models\UserLanguages;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

use App\Interfaces\RepositoryInterfaces\BookingRepositoryInterface;
use Exception;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository implements BookingRepositoryInterface
{

    protected $model;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model)
    {
        parent::__construct($model);
        $this->logger = new Logger('admin_logger');

    }



    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory(string $user_id, $request)
    {
        try
        {
            $page = $request->get('page');
            if (isset($page)) {
                $pagenum = $page;
            } else {
                $pagenum = "1";
            }
            $cuser = User::find($user_id);
            $usertype = '';
            $emergencyJobs = array();
            $noramlJobs = array();
            if ($cuser && $cuser->is('customer')) {
                $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])->orderBy('due', 'desc')->paginate(15);
                $usertype = 'customer';
                return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => [], 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => 0, 'pagenum' => 0];
            } elseif ($cuser && $cuser->is('translator')) {
                $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
                $totaljobs = $jobs_ids->total();
                $numpages = ceil($totaljobs / 15);

                $usertype = 'translator';

                $jobs = $jobs_ids;
                $noramlJobs = $jobs_ids;
                return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => $numpages, 'pagenum' => $pagenum];
            }
        }
        catch( Exception $e )
        {
            throw $e->getMessage();
        }
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store(string $user, array $data)
    {
        DB::beginTransaction();
        try
        {
            $immediatetime = 5;
            $consumer_type = $user->userMeta->consumer_type;
            if ($user->user_type == env('CUSTOMER_ROLE_ID')) {
                $cuser = $user;

                if (!isset($data['from_language_id'])) {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "from_language_id";
                    return $response;
                }
                if ($data['immediate'] == 'no') {
                    if (isset($data['due_date']) && $data['due_date'] == '') {
                        $response['status'] = 'fail';
                        $response['message'] = "Du måste fylla in alla fält";
                        $response['field_name'] = "due_date";
                        return $response;
                    }
                    if (isset($data['due_time']) && $data['due_time'] == '') {
                        $response['status'] = 'fail';
                        $response['message'] = "Du måste fylla in alla fält";
                        $response['field_name'] = "due_time";
                        return $response;
                    }
                    if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                        $response['status'] = 'fail';
                        $response['message'] = "Du måste göra ett val här";
                        $response['field_name'] = "customer_phone_type";
                        return $response;
                    }
                    if (isset($data['duration']) && $data['duration'] == '') {
                        $response['status'] = 'fail';
                        $response['message'] = "Du måste fylla in alla fält";
                        $response['field_name'] = "duration";
                        return $response;
                    }
                } else {
                    if (isset($data['duration']) && $data['duration'] == '') {
                        $response['status'] = 'fail';
                        $response['message'] = "Du måste fylla in alla fält";
                        $response['field_name'] = "duration";
                        return $response;
                    }
                }
                if (isset($data['customer_phone_type'])) {
                    $data['customer_phone_type'] = 'yes';
                } else {
                    $data['customer_phone_type'] = 'no';
                }

                if (isset($data['customer_physical_type'])) {
                    $data['customer_physical_type'] = 'yes';
                    $response['customer_physical_type'] = 'yes';
                } else {
                    $data['customer_physical_type'] = 'no';
                    $response['customer_physical_type'] = 'no';
                }

                if ($data['immediate'] == 'yes') {
                    $due_carbon = Carbon::now()->addMinute($immediatetime);
                    $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                    $data['immediate'] = 'yes';
                    $data['customer_phone_type'] = 'yes';
                    $response['type'] = 'immediate';

                } else {
                    $due = $data['due_date'] . " " . $data['due_time'];
                    $response['type'] = 'regular';
                    $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                    $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                    if ($due_carbon->isPast()) {
                        $response['status'] = 'fail';
                        $response['message'] = "Can't create booking in past";
                        return $response;
                    }
                }
                if (in_array('male', $data['job_for'])) {
                    $data['gender'] = 'male';
                } else if (in_array('female', $data['job_for'])) {
                    $data['gender'] = 'female';
                }
                if (in_array('normal', $data['job_for'])) {
                    $data['certified'] = 'normal';
                }
                else if (in_array('certified', $data['job_for'])) {
                    $data['certified'] = 'yes';
                } else if (in_array('certified_in_law', $data['job_for'])) {
                    $data['certified'] = 'law';
                } else if (in_array('certified_in_helth', $data['job_for'])) {
                    $data['certified'] = 'health';
                }
                if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
                    $data['certified'] = 'both';
                }
                else if(in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for']))
                {
                    $data['certified'] = 'n_law';
                }
                else if(in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for']))
                {
                    $data['certified'] = 'n_health';
                }
                if ($consumer_type == 'rwsconsumer')
                    $data['job_type'] = 'rws';
                else if ($consumer_type == 'ngo')
                    $data['job_type'] = 'unpaid';
                else if ($consumer_type == 'paid')
                    $data['job_type'] = 'paid';
                $data['b_created_at'] = date('Y-m-d H:i:s');
                if (isset($due))
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
                $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

                $job = $cuser->jobs()->create($data);

                $response['status'] = 'success';
                $response['id'] = $job->id;
                $data['job_for'] = array();
                if ($job->gender != null) {
                    if ($job->gender == 'male') {
                        $data['job_for'][] = 'Man';
                    } else if ($job->gender == 'female') {
                        $data['job_for'][] = 'Kvinna';
                    }
                }
                if ($job->certified != null) {
                    if ($job->certified == 'both') {
                        $data['job_for'][] = 'normal';
                        $data['job_for'][] = 'certified';
                    } else if ($job->certified == 'yes') {
                        $data['job_for'][] = 'certified';
                    } else {
                        $data['job_for'][] = $job->certified;
                    }
                }

                $data['customer_town'] = $cuser->userMeta->city;
                $data['customer_type'] = $cuser->userMeta->customer_type;

            } else {
                $response['status'] = 'fail';
                $response['message'] = "Translator can not create booking";
            }
        }
        catch( Exception $e )
        {
            DB::rollback();
            throw $e->getMessage();
        }
        DB::commit();
        return $response;

    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        DB::beginTransaction();
        try
        {
            $user_type = $data['user_type'];
            $job = $this->model::findOrFail(@$data['user_email_job_id']);
            $job->user_email = @$data['user_email'];
            $job->reference = isset($data['reference']) ? $data['reference'] : '';
            $user = $job->user()->get()->first();
            if (isset($data['address'])) {
                $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
                $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
                $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
            }
            $job->save();

            if (!empty($job->user_email)) {
                $email = $job->user_email;
                $name = $user->name;
            } else {
                $email = $user->email;
                $name = $user->name;
            }
            $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
            $send_data = [
                'user' => $user,
                'job'  => $job
            ];
            $response['event'] = 'emails.job-created';
            $response['mailerData'] = $send_data;
            $response['email'] = $email;
            $response['name'] = $name;
            $response['subject'] = $subject;
            $response['type'] = $user_type;
            $response['job'] = $job;
            $response['status'] = 'success';
            $data = $this->jobToData($job);
            $response['data'] = $data;
        }
        catch( Exception $e )
        {
            DB::rollback();
            throw $e->getMessage();
        }
        DB::commit();
        return $response;

    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {

        $data = array();            // save job's information to data for sending Push
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];

        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } else if ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rätttstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;

    }

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = array())
    {
        DB::beginTransaction();
        try
        {
            $completeddate = date('Y-m-d H:i:s');
            $jobid = $post_data["job_id"];
            $job_detail = $this->model::with('translatorJobRel')->find($jobid);
            $duedate = $job_detail->due;
            $start = date_create($duedate);
            $end = date_create($completeddate);
            $diff = date_diff($end, $start);
            $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
            $job = $job_detail;
            $job->end_at = date('Y-m-d H:i:s');
            $job->status = 'completed';
            $job->session_time = $interval;

            $user = $job->user()->get()->first();
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $name = $user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $session_explode = explode(':', $job->session_time);
            $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
            $data = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];
            $job->save();

            $tr = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

            $user = $tr->user()->first();
            $email = $user->email;
            $name = $user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $data = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];
            $mailer = new AppMailer();
            $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

            $tr->completed_at = $completeddate;
            $tr->completed_by = $post_data['userid'];
            $tr->save();
        }
        catch( Exception $e )
        {
            DB::rollback();
            throw $e->getMessage();
        }
        DB::commit();
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;
        $job_type = 'unpaid';
        if ($translator_type == 'professional')
            $job_type = 'paid';   /*show all jobs for professionals.*/
        else if ($translator_type == 'rwstranslator')
            $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
        else if ($translator_type == 'volunteer')
            $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $user_id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;
        $job_ids = $this->model::getJobs($user_id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
        foreach ($job_ids as $k => $v)     // checking translator town
        {
            $job = $this->model::find($v->id);
            $jobuserid = $job->user_id;
            $checktown = $this->model::checkTowns($jobuserid, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $jobs;
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) return false;
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        if ($not_get_nighttime == 'yes') return true;
        return false;
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        if ($not_get_notification == 'yes') return false;
        return true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
 

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {

        $job_type = $job->job_type;

        if ($job_type == 'paid')
            $translator_type = 'professional';
        else if ($job_type == 'rws')
            $translator_type = 'rwstranslator';
        else if ($job_type == 'unpaid')
            $translator_type = 'volunteer';

        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = [];
        if (!empty($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
            }
            elseif($job->certified == 'law' || $job->certified == 'n_law')
            {
                $translator_level[] = 'Certified with specialisation in law';
            }
            elseif($job->certified == 'health' || $job->certified == 'n_health')
            {
                $translator_level[] = 'Certified with specialisation in health care';
            }
            else if ($job->certified == 'normal' || $job->certified == 'both') {
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
            elseif ($job->certified == null) {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translatorsId);


        return $users;

    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        DB::beginTransaction();
        try
        {
            $job = $this->model::find($id);

            $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
            if (is_null($current_translator))
                $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

            $log_data = [];

            $langChanged = false;

            $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
            if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

            $changeDue = $this->changeDue($job->due, $data['due']);
            if ($changeDue['dateChanged']) {
                $old_time = $job->due;
                $job->due = $data['due'];
                $log_data[] = $changeDue['log_data'];
            }

            if ($job->from_language_id != $data['from_language_id']) {
                $log_data[] = [
                    'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                    'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
                ];
                $old_lang = $job->from_language_id;
                $job->from_language_id = $data['from_language_id'];
                $langChanged = true;
            }

            $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
            if ($changeStatus['statusChanged'])
                $log_data[] = $changeStatus['log_data'];

            $job->admin_comments = $data['admin_comments'];

            $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

            $job->reference = $data['reference'];
            $job->save();

            if ($job->due > Carbon::now()) {
                $response['translatorChanged'] = $changeTranslator['translatorChanged'];
                $response['langChanged'] = $langChanged;
            }
            $response['changeDue'] = $changeDue;
        }
        catch( Exception $e )
        {
            DB::rollback();
            throw $e->getMessage();
        }
        DB::commit();
        return $response;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;
        if ($old_status != $data['status']) {

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }
    }

    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];

    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        DB::beginTransaction();
        try
        {
            $cuser = $user;
            $job_id = $data['job_id'];
            $job = $this->model::findOrFail($job_id);
            if (!$this->model::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
                if ($job->status == 'pending' && $this->model::insertTranslatorJobRel($cuser->id, $job_id)) {
                    $job->status = 'assigned';
                    $job->save();
                    $user = $job->user()->get()->first();

                    if (!empty($job->user_email)) {
                        $email = $job->user_email;
                        $name = $user->name;
                        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                    } else {
                        $email = $user->email;
                        $name = $user->name;
                        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                    }
                    $data = [
                        'user' => $user,
                        'job'  => $job
                    ];
                    $response['email'] = $email;
                    $response['name'] = $name;
                    $response['subject'] = $subject;
                    $response['event'] = 'emails.job-accepted';
                    $response['mailerData'] = $data;
                }
                /*@todo
                    add flash message here.
                */
                $jobs = $this->getPotentialJobs($cuser);
                $response = array();
                $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
            }
        }
        catch( Exception $e )
        {
            DB::rollback();
            throw $e->getMessage();
        }
        DB::commit();
        return $response;

    }

    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $cuser)
    {
        DB::beginTransaction();
        try
        {
            $job = $this->model::findOrFail($job_id);
            $response = array();

            if (!$this->model::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
                if ($job->status == 'pending' && $this->model::insertTranslatorJobRel($cuser->id, $job_id)) {
                    $job->status = 'assigned';
                    $job->save();
                    $user = $job->user()->get()->first();

                    if (!empty($job->user_email)) {
                        $email = $job->user_email;
                        $name = $user->name;
                    } else {
                        $email = $user->email;
                        $name = $user->name;
                    }
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                    $data = [
                        'user' => $user,
                        'job'  => $job
                    ];
                    $response['email'] = $email;
                    $response['name'] = $name;
                    $response['subject'] = $subject;
                    $response['event'] = 'emails.job-accepted';
                    $response['mailerData'] = $data;

                    $data = array();
                    $data['notification_type'] = 'job_accepted';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = array(
                        "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                    );
                    if ($this->isNeedToSendPush($user->id)) {
                        $users_array = array($user);
                    }
                    // Your Booking is accepted sucessfully
                    $response['status'] = 'success';
                    $response['list']['job'] = $job;
                    $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
                } else {
                    // Booking already accepted by someone else
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $response['status'] = 'fail';
                    $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
                }
            } else {
                // You already have a booking the time
                $response['status'] = 'fail';
                $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
            }

        }
        catch( Exception $e )
        {
            DB::rollback();
            throw $e->getMessage();
        }
        DB::commit();
        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        DB::beginTransaction();
        try
        {
            $response = array();
            $cuser = $user;
            $job_id = $data['job_id'];
            $job = $this->model::findOrFail($job_id);
            $translator = $this->model::getJobsAssignedTranslatorDetail($job);
            if ($cuser->is('customer')) {
                $job->withdraw_at = Carbon::now();
                if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                    $job->status = 'withdrawbefore24';
                    $response['jobstatus'] = 'success';
                } else {
                    $job->status = 'withdrawafter24';
                    $response['jobstatus'] = 'success';
                }
                $job->save();
                $response['job'] = $job;
                $response['status'] = 'success';
                $response['jobstatus'] = 'success';
                if ($translator) {
                    $data = array();
                    $data['notification_type'] = 'job_cancelled';
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = array(
                        "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                    );
                    if ($this->isNeedToSendPush($translator->id)) {
                        $users_array = array($translator);
                        $response['users_array'] = $users_array;
                        $response['job_id'] = $job_id;
                        $response['data'] = $data;
                        $response['msg_text'] = $msg_text;
                        $response['isNeedToDelayPush'] = $this->isNeedToDelayPush($translator->id);
                    }
                }
            } else {
                if ($job->due->diffInHours(Carbon::now()) > 24) {
                    $customer = $job->user()->get()->first();
                    if ($customer) {
                        $data = array();
                        $data['notification_type'] = 'job_cancelled';
                        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                        $msg_text = array(
                            "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                        );
                        if ($this->isNeedToSendPush($customer->id)) {
                            $users_array = array($customer);
                            $response['users_array'] = $users_array;
                            $response['job_id'] = $job_id;
                            $response['data'] = $data;
                            $response['msg_text'] = $msg_text;
                            $response['isNeedToDelayPush'] = $this->isNeedToDelayPush($translator->id);
                        }
                    }
                    $job->status = 'pending';
                    $job->created_at = date('Y-m-d H:i:s');
                    $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                    $job->save();
                    $this->model::deleteTranslatorJobRel($translator->id, $job_id);

                    $data = $this->jobToData($job);
                    $response['job'] = $job;
                    $response['data'] = $data;
                    $response['translatorId'] = $translator->id;
                    $response['status'] = 'success';
                } else {
                    $response['status'] = 'fail';
                    $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
                }
            }
       
        }
        catch( Exception $e )
        {
            DB::rollback();
            throw $e->getMessage();
        }
        DB::commit();     
        return $response;
    }

    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = 'unpaid';
        $translator_type = $cuser_meta->translator_type;
        if ($translator_type == 'professional')
            $job_type = 'paid';   /*show all jobs for professionals.*/
        else if ($translator_type == 'rwstranslator')
            $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
        else if ($translator_type == 'volunteer')
            $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;
        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $job_ids = $this->model::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = $this->model::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = $this->model::checkParticularJob($cuser->id, $job);
            $checktown = $this->model::checkTowns($jobuserid, $cuser->id);

            if($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob')
                unset($job_ids[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
        return $job_ids;
    }

    public function endJob($post_data)
    {
        DB::beginTransaction();
        try
        {
            $completeddate = date('Y-m-d H:i:s');
            $jobid = $post_data["job_id"];
            $job_detail = $this->model::with('translatorJobRel')->find($jobid);

            if($job_detail->status != 'started')
                return ['status' => 'success'];

            $duedate = $job_detail->due;
            $start = date_create($duedate);
            $end = date_create($completeddate);
            $diff = date_diff($end, $start);
            $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
            $job = $job_detail;
            $job->end_at = date('Y-m-d H:i:s');
            $job->status = 'completed';
            $job->session_time = $interval;

            $user = $job->user()->get()->first();
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $name = $user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $session_explode = explode(':', $job->session_time);
            $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
            $data = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];
            $job->save();
            $response['email'] = $email;
            $response['name'] = $name;
            $response['subject'] = $subject;
            $response['event'] = 'emails.session-ended';
            $response['mailerData'] = $data;

            $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
            $response['user'] = $tr->user()->first();

            $tr->completed_at = $completeddate;
            $tr->completed_by = $post_data['user_id'];
            $tr->save();
            $response['status'] = 'success';
        }
        catch( Exception $e )
        {
            DB::rollback();
            throw $e->getMessage();
        }
        DB::commit();
        return $response;
    }


    public function customerNotCall($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = $this->model::with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function getAll($requestdata, $cuser, $limit = null)
    {
        $consumer_type = $cuser->consumer_type;

        $allJobs = $this->model::query();
        if (isset($requestdata['id']) && $requestdata['id'] != '') {
            $allJobs->whereIn('id', (array) $requestdata['id']);
            $requestdata = array_only($requestdata, ['id']);
        }
        $allJobs->where('job_type', '=', $consumer_type == 'RWS' ? 'rws' : 'unpaid');

        if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
            $allJobs->where('ignore_feedback', '0');
            $allJobs->whereHas('feedback', function ($q) {
                $q->where('rating', '<=', '3');
            });
            if (isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
        }

        if (isset($requestdata['lang']) && $requestdata['lang'] != '') $allJobs->whereIn('from_language_id', $requestdata['lang']);
        if (isset($requestdata['status']) && $requestdata['status'] != '') $allJobs->whereIn('status', $requestdata['status']);
        if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') $allJobs->whereIn('job_type', $requestdata['job_type']);
        if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
            // Change DB::table('user) to User ORM
            $user = User::whereEmail($requestdata['customer_email'])->first();
            if ($user) $allJobs->where('user_id', '=', $user->id);
        }
        if (isset($requestdata['filter_timetype'])) {
            if ($requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") $allJobs->where('created_at', '>=', $requestdata["from"]);
                if (isset($requestdata['to']) && $requestdata['to'] != "") $allJobs->where('created_at', '<=', ($requestdata["to"] . " 23:59:00")); // removed unneccessary $to variable to save memory and exceution
                $allJobs->orderBy('created_at', 'desc');
            }
            if ($requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") $allJobs->where('due', '>=', $requestdata["from"]);
                if (isset($requestdata['to']) && $requestdata['to'] != "") $allJobs->where('due', '<=', ($requestdata["to"] . " 23:59:00")); // removed unneccessary $to variable to save memory and 
                $allJobs->orderBy('due', 'desc');
            }
        }

        // moved above code outside of the if else as it was duplicating and should be executed in all cases

        if ($cuser && $cuser->user_type == config('role.SUPERADMIN_ROLE_ID')) // assuming roles are added in config file converting env to config method
        {

            if (isset($requestdata['expired_at']) && $requestdata['expired_at'] != '') $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
            if (isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '') $allJobs->where('will_expire_at', '>=', $requestdata['will_expire_at']);

            if (isset($requestdata['translator_email']) && count($requestdata['translator_email'])) {
                $users = User::whereIn('email', $requestdata['translator_email'])->get();
                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }

            if (isset($requestdata['physical'])) $allJobs->where('customer_physical_type', $requestdata['physical'])->where('ignore_physical', 0);

            if (isset($requestdata['phone'])) {
                $allJobs->where('customer_phone_type', $requestdata['phone']);
                if (isset($requestdata['physical'])) $allJobs->where('ignore_physical_phone', 0);
            }

            if (isset($requestdata['flagged'])) $allJobs->where('flagged', $requestdata['flagged'])->where('ignore_flagged', 0);

            if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty')  $allJobs->whereDoesntHave('distance');

            if (isset($requestdata['salary']) &&  $requestdata['salary'] == 'yes') $allJobs->whereDoesntHave('user.salaries');

            if (isset($requestdata['count']) && $requestdata['count'] == 'true') return ['count' => $allJobs->count()];

            if (isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '') {
                $allJobs->whereHas('user.userMeta', function ($q) use ($requestdata) {
                    $q->where('consumer_type', $requestdata['consumer_type']);
                });
            }

            if (isset($requestdata['booking_type'])) {
                if ($requestdata['booking_type'] == 'physical') $allJobs->where('customer_physical_type', 'yes');
                if ($requestdata['booking_type'] == 'phone') $allJobs->where('customer_phone_type', 'yes');
            }
        }

        $allJobs->orderBy('created_at', 'desc');
        $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
        if ($limit == 'all') $allJobs = $allJobs->get();
        else $allJobs = $allJobs->paginate(15);
        return $allJobs;
    }

    public function alerts()
    {
        $jobs = $this->model::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs [$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId [] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);

        }
        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function ignoreExpiring($id)
    {
        $job = $this->model::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = $this->model::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        DB::beginTransaction();
        try
        {
            $jobid = $request['jobid'];
            $userid = $request['userid'];

            $job = $this->model::find($jobid);
            $job = $job->toArray();

            $data = array();
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
            $data['updated_at'] = date('Y-m-d H:i:s');
            $data['user_id'] = $userid;
            $data['job_id'] = $jobid;
            $data['cancel_at'] = Carbon::now();

            $datareopen = array();
            $datareopen['status'] = 'pending';
            $datareopen['created_at'] = Carbon::now();
            $datareopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);

            if ($job['status'] != 'timedout') {
                $affectedRows = $this->model::where('id', '=', $jobid)->update($datareopen);
                $new_jobid = $jobid;
            } else {
                $job['status'] = 'pending';
                $job['created_at'] = Carbon::now();
                $job['updated_at'] = Carbon::now();
                $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
                $job['updated_at'] = date('Y-m-d H:i:s');
                $job['cust_16_hour_email'] = 0;
                $job['cust_48_hour_email'] = 0;
                $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
                $affectedRows = $this->model::create($job);
                $new_jobid = $affectedRows['id'];
            }
            Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
            Translator::create($data);
        }
        catch( Exception $e )
        {
            DB::rollback();
            throw $e->getMessage();
        }
        DB::commit();
        if (isset($affectedRows)) {
            return ['respone' => $affectedRows,'message' => "Tolk cancelled!", 'newJobId' => $new_jobid];
        } else {
            return ['respone' => $affectedRows, 'error' => "Please try again!"];
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    
    public function assignedToPaticularTranslator($userId, $oneJobId)
    {
        return $this->model::assignedToPaticularTranslator($userId, $oneJobId);
    }

    public function checkParticularJob($userId, $oneJob)
    {
        return $this->model::assignedToPaticularTranslator($userId, $oneJob);
    }

    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];

        }

        return ['translatorChanged' => $translatorChanged];
    }


}
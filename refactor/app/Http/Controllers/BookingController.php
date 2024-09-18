<?php

namespace DTApi\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\BookingRequest;
use DTApi\Repository\BookingRepository;

use App\Interfaces\ServiceInterfaces\BookingServiceInterface; 

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $service;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingServiceInterface $bookingService)
    {
        $this->service = $bookingService;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        return $this->service->getJobs($request);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        return $this->service->showJobs($id);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(BookingRequest $request)
    {
        return $this->service->storeBooking($request->__authenticatedUser, $request->validated());
    }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, BookingRequest $request)
    {
        return $this->serice->updateJob($id, $request->validated(), $request->__authenticatedUser);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(JobEmailRequest $request)
    {
        return $this->service->storeJobEmail($request->validated());

    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        return $this->service->getUsersJobsHistory($request->get('user_id'), $request->all());
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        return $this->service->acceptJob($request->all(), $request->__authenticatedUser);
    }

    public function acceptJobWithId(Request $request)
    {
        return $this->service->acceptJobWithId($request->get('job_id'), $request->__authenticatedUser);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        return $this->service->cancelJobAjax($request->all(), $request->__authenticatedUser);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        return $this->service->endJob($request->all());


    }

    public function customerNotCall(Request $request)
    {
        return $this->service->customerNotCall($request->all());
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        return $this->service->getPotentialJobs($request->__authenticatedUser);
    }

    public function distanceFeed(DistanceFeeRequest $request)
    {
        return $this->serice->distanceFeed($request->validated());
    }

    public function reopen(Request $request)
    {
        return $this->service->reopen($request->all());
    }

    public function resendNotifications(Request $request)
    {
        return $this->service->sendJobDataNotifications($request->jobid);
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        return $this->service->sendJobDataNotifications($request->jobid, true);
    }

}

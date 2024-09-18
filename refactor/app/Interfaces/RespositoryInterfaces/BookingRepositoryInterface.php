<?php

namespace App\Interfaces\RepositoryInterfaces;

use Illuminate\Support\Facades\Request;
use DTApi\Models\Job;
use DTApi\Models\User;

interface BookingRepositoryInterface
{
    public function getUsersJobsHistory(string $userId, Request $request);
    public function store(string $user, array $data);
    public function storeJobEmail(array $data);
    public function jobToData(array $job);
    public function jobEnd(array $post_data);
    public function getPotentialJobIdsWithUserId($userId);
    public function isNeedToDelayPush(string $userId);
    public function isNeedToSendPush(string $userId);
    public function getPotentialTranslators(Job $job);
    public function updateJob(string $id,array $data,string $cuser);
    public function acceptJob(array $data,User $user);
    public function acceptJobWithId(string $job_id,User $cuser);
    public function cancelJobAjax(array $data,User $user);
    public function endJob(array $postData);
    public function reopen(array $data);



}
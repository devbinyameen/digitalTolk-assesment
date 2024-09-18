<?php

namespace App\Interfaces\ServiceInterfaces;

use DTApi\Models\User;

interface BookingServiceInterface
{
    public function getJobs(array $request);
    public function showJobs(string $id);
    public function storeBooking(string $authenticatedUser,array $data);
    public function updateBooking(string $id,array $data,string $authenticatedUser);
    public function storeJobEmail(array $data);
    public function getUsersJobsHistory(string $userId, array $data);
    public function acceptJob(array $data, string $user);
    public function acceptJobWithId(array $data, string $user);
    public function cancelJob(array $data,string $user);
    public function endJob(array $data);
    public function customerNotCall(array $data);
    public function getPotentialJobs(User $user);
    public function reopen(array $data);


}

<?php

namespace App\Http\Controllers;

use App\Models\Timer;
use App\Http\Requests\TimerRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class TimerController extends Controller
{
    /**
     * Fetch all timers.
     */
    public function getAllTimers()
    {
        $timers = Timer::all();
        return response()->json([
            'message' => 'Timers fetched successfully.',
            'timers' => $timers,
        ], 200);
    }

    /**
     * Create a new timer.
     */
    public function createTimer(TimerRequest $request)
    {
        // Get the authenticated user's ID
        $data = $request->validated();
        $data['created_by'] = Auth::id(); // Set the creator of the timer

        // Create the timer
        $timer = Timer::create($data);

        return response()->json([
            'message' => 'Timer created successfully.',
            'timer' => $timer,
        ], 201);
    }

    /**
     * Fetch a specific timer by ID.
     */
    public function getTimerById($id)
    {
        $timer = Timer::findOrFail($id);

        return response()->json([
            'message' => 'Timer fetched successfully.',
            'timer' => $timer,
        ], 200);
    }

    /**
     * Update an existing timer by ID.
     */
    public function updateTimer(TimerRequest $request, $id)
    {
        // Get the authenticated user's ID
        $data = $request->validated();
        $data['updated_by'] = Auth::id(); // Set the updater of the timer

        $timer = Timer::findOrFail($id);

        // Update the timer
        $timer->update($data);

        return response()->json([
            'message' => 'Timer updated successfully.',
            'timer' => $timer,
        ], 200);
    }

    /**
     * Delete a timer by ID.
     */
    public function deleteTimer($id)
    {
        $timer = Timer::findOrFail($id);

        // Update the deleted_by field and then delete the timer
        $timer->deleted_by = Auth::id();       
        $timer->save();
        $timer->delete();

        return response()->json([
            'message' => 'Timer deleted successfully.',
        ], 200);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\VehicleAssignmentRequest;
use App\Models\VehicleAssignment;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VehicleAssignmentController extends Controller
{
    // Get all vehicle assignments
    public function getAllAssignments() {
        $assignments = VehicleAssignment::with('vehicle.trackerMapping', 'userProfiles')->get(); // Load user profiles
        return response()->json($assignments);
    }

    // Create a new vehicle assignment
    public function createAssignment(VehicleAssignmentRequest $request) {
        try {
            $data = $request->validated();
            $data['created_by'] = Auth::id(); // Automatically set created_by

            // Check if any user profiles are already on duty
            if ($request->has('user_profile_ids')) {
                $userProfileIds = $request->input('user_profile_ids');
                $onDutyProfiles = UserProfile::whereIn('user_profile_id', $userProfileIds)
                    ->where('status', 'on_duty')
                    ->get();

                if ($onDutyProfiles->isNotEmpty()) {
                    $onDutyDetails = $onDutyProfiles->map(function ($profile) {
                        return [
                            'user_profile_id' => $profile->user_profile_id,
                            'name' => $profile->last_name, // Assuming you have a `last_name` field
                        ];
                    });

                    return response()->json([
                        'error' => 'Some user profiles are already assigned.',
                        'on_duty_profiles' => $onDutyDetails
                    ], 400);
                }
            }

            // Filter out invalid user profile IDs
            if ($request->has('user_profile_ids')) {
                $userProfiles = $request->input('user_profile_ids');
                $invalidProfiles = UserProfile::whereIn('user_profile_id', $userProfiles)
                    ->where('position', 'dispatcher')
                    ->pluck('user_profile_id')
                    ->toArray();

                if (!empty($invalidProfiles)) {
                    return response()->json([
                        'error' => 'User profiles with the "dispatcher" position cannot be assigned to vehicles.',
                        'invalid_user_profiles' => $invalidProfiles, // Provide the invalid user profile IDs
                    ], 400);
                }
            }

            // Create the vehicle assignment
            $assignment = VehicleAssignment::create($data);

            // Attach the user profiles
            if ($request->has('user_profile_ids')) {
                $userProfileIds = $request->input('user_profile_ids');
                $assignment->userProfiles()->attach($userProfileIds);

                // Update the status of the user profiles to 'on_duty'
                foreach ($userProfileIds as $userProfileId) {
                    $userProfile = UserProfile::find($userProfileId);
                    if ($userProfile) {
                        $userProfile->status = 'on_duty';
                        $userProfile->save();
                    }
                }
            }

            return response()->json(["message" => "Vehicle Assignment Successfully Created", "assignment" => $assignment->load('vehicle', 'userProfiles')], 201);
        } catch (\Exception $e) {
            return response()->json(["message" => $e->getMessage()], 400);
        }
    }

    // Get a specific vehicle assignment by ID
    public function getAssignmentById($id) {
        try {
            $assignment = VehicleAssignment::with('vehicle', 'userProfiles')->findOrFail($id); // Load user profiles
            return response()->json($assignment);
        } catch (\Exception $e) {
            return response()->json(["message" => $e->getMessage()], 404);
        }
    }

    // Update a specific vehicle assignment by ID
    public function updateAssignment(VehicleAssignmentRequest $request, $id)
    {
        try {
            $assignment = VehicleAssignment::findOrFail($id);
            $data = $request->validated();
            $data['updated_by'] = Auth::id(); // Automatically set updated_by

            // Get the current user profile IDs assigned to the vehicle
            $currentUserProfileIds = $assignment->userProfiles->pluck('user_profile_id')->toArray();

            // If new user profiles are provided, handle the status updates
            if ($request->has('user_profile_ids')) {
                $userProfileIds = $request->input('user_profile_ids');
                
                // Filter out invalid user profile IDs
                $invalidProfiles = UserProfile::whereIn('user_profile_id', $userProfileIds)
                    ->where('position', 'dispatcher')
                    ->pluck('user_profile_id')
                    ->toArray();

                if (!empty($invalidProfiles)) {
                    return response()->json([
                        'error' => 'User profiles with the "dispatcher" position cannot be assigned to vehicles.',
                        'invalid_user_profiles' => $invalidProfiles,
                    ], 400);
                }

                // Sync the new user profiles to the assignment
                $assignment->userProfiles()->sync($userProfileIds);

                // Update the status of the newly assigned profiles to 'on_duty'
                foreach ($userProfileIds as $userProfileId) {
                    $userProfile = UserProfile::find($userProfileId);
                    if ($userProfile) {
                        $userProfile->status = 'on_duty';
                        $userProfile->save();
                    }
                }

                // Update the status of the profiles no longer assigned to 'off_duty'
                $profilesToOffDuty = array_diff($currentUserProfileIds, $userProfileIds);
                foreach ($profilesToOffDuty as $userProfileId) {
                    $userProfile = UserProfile::find($userProfileId);
                    if ($userProfile) {
                        $userProfile->status = 'off_duty';
                        $userProfile->save();
                    }
                }
            }

            // Update the vehicle assignment with the new data
            $assignment->update($data);

            return response()->json([
                "message" => "Vehicle Assignment Updated Successfully",
                "assignment" => $assignment->load('vehicle', 'userProfiles')
            ], 200);
        } catch (\Exception $e) {
            return response()->json(["message" => $e->getMessage()], 400);
        }
    }

    // Delete a specific vehicle assignment by ID
    public function deleteAssignment($id) {
        try {
            // Find the vehicle assignment by ID
            $assignment = VehicleAssignment::findOrFail($id);
            
            // Update the status of the user profiles to 'off_duty'
            $userProfiles = $assignment->userProfiles;
            foreach ($userProfiles as $userProfile) {
                $userProfile->status = 'off_duty';
                $userProfile->save();
            }

            // Set deleted_by to the authenticated user
            $assignment->deleted_by = Auth::id(); // Automatically set deleted_by
            $assignment->save();
            
            // Delete the assignment
            $assignment->delete();

            return response()->json(["message" => "Vehicle Assignment Deleted Successfully"], 200);
        } catch (\Exception $e) {
            return response()->json(["message" => $e->getMessage()], 400);
        }
    }

    // Get user profiles by status (on_duty or off_duty)
    public function getUserProfilesByStatus($status)
    {
        try {
            // Validate the status input
            if (!in_array($status, ['on_duty', 'off_duty'])) {
                return response()->json(["error" => "Invalid status. Use 'on_duty' or 'off_duty'."], 400);
            }

            // Filter user profiles based on the status
            $userProfiles = UserProfile::where('status', $status)->get();

            return response()->json($userProfiles);
        } catch (\Exception $e) {
            return response()->json(["message" => $e->getMessage()], 400);
        }
    }

}

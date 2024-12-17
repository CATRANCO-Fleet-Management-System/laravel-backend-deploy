<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\TrackerVehicleMapping;
use App\Models\DispatchLogs;
use App\Events\FlespiDataReceived;
use Illuminate\Support\Facades\Cache;

class FlespiController extends Controller
{
    // Predefined blacklist of coordinates
    protected $blacklistedCoordinates = [
        // ['latitude' => 8.458932, 'longitude' => 124.6326], // Example coordinate
        // ['latitude' => 8.458825, 'longitude' => 124.632733], // Example coordinate
        // ['latitude' => 8.458887, 'longitude' => 124.6327], // Example coordinate
    ];

    /**
     * Handle incoming data from the Flespi stream.
     */
    public function handleData(Request $request)
    {
        // Extract Flespi data (assuming it's in an array)
        $dataList = $request->all();

        if (!is_array($dataList) || count($dataList) === 0) {
            Log::warning("No valid data received.");
            return response()->json(['status' => 'failed', 'message' => 'No data found']);
        }

        $responses = [];

        foreach ($dataList as $data) {
            // Extract individual fields from the data
            $trackerIdent = $data['Ident'] ?? null;
            $latitude = $data['PositionLatitude'] ?? null;
            $longitude = $data['PositionLongitude'] ?? null;
            $speed = $data['PositionSpeed'] ?? null;
            $timestamp = $data['Timestamp'] ?? null;

            if (!$trackerIdent) {
                Log::warning("Tracker identifier is missing.");
                $responses[] = ['status' => 'failed', 'message' => 'Tracker identifier missing'];
                continue;
            }

            // Check if the coordinates are blacklisted with a tolerance
            $blacklisted = false;
            foreach ($this->blacklistedCoordinates as $blacklistedCoord) {
                // Using a small tolerance for floating point comparison
                if (abs($blacklistedCoord['latitude'] - $latitude) < 0.0001 &&
                    abs($blacklistedCoord['longitude'] - $longitude) < 0.0001) {
                    Log::info("Ignoring blacklisted coordinates for tracker $trackerIdent: latitude $latitude, longitude $longitude.");
                    $responses[] = ['status' => 'ignored', 'message' => 'Coordinates are blacklisted'];
                    $blacklisted = true;
                    break; // Skip this tracker if it's blacklisted
                }
            }

            if ($blacklisted) {
                continue;
            }

            // Log tracker movement status
            if ($speed > 0) {
                Log::info("Tracker $trackerIdent is moving.", $data);
            } else {
                Log::info("Tracker $trackerIdent is stationary.", $data);
            }

            // Match tracker to a vehicle using TrackerVehicleMapping
            $vehicleId = TrackerVehicleMapping::where('tracker_ident', $trackerIdent)->value('vehicle_id');

            // Fetch the active dispatch log for the vehicle
            $dispatchLog = null;
            if ($vehicleId) {
                $dispatchLog = DispatchLogs::where('vehicle_assignment_id', $vehicleId)
                    ->whereIn('status', ['on alley', 'on road'])
                    ->with(['vehicleAssignments.vehicle', 'vehicleAssignments.userProfiles'])
                    ->first();
            }

            // Prepare data for broadcast
            $broadcastData = [
                'tracker_ident' => $trackerIdent,
                'vehicle_id' => $vehicleId,
                'location' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'speed' => $speed,
                ],
                'timestamp' => $timestamp,
                'dispatch_log' => $dispatchLog ? [
                    'dispatch_logs_id' => $dispatchLog->dispatch_logs_id,
                    'start_time' => $dispatchLog->start_time,
                    'end_time' => $dispatchLog->end_time,
                    'status' => $dispatchLog->status,
                    'route' => $dispatchLog->route,
                    'vehicle_assignment' => [
                        'vehicle_assignment_id' => $dispatchLog->vehicleAssignments->vehicle_assignment_id,
                        'user_profiles' => $dispatchLog->vehicleAssignments->userProfiles->map(function ($profile) {
                            return [
                                'user_profile_id' => $profile->user_profile_id,
                                'name' => "{$profile->first_name} {$profile->last_name}",
                                'position' => $profile->position,
                                'status' => $profile->status,
                            ];
                        }),
                    ],
                ] : null,
            ];

            // Log the data being broadcasted
            Log::info("Broadcasting data", $broadcastData);

            // Broadcast data to the frontend
            broadcast(new FlespiDataReceived($broadcastData));

            sleep(5);

            $responses[] = ['status' => 'success', 'tracker_ident' => $trackerIdent];
        }

        return response()->json(['status' => 'processed', 'responses' => $responses]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrackerVehicleMapping extends Model
{
    use HasFactory;

    protected $table = 'tracker_vehicle_mapping';
    protected $fillable = ['device_name',  'tracker_ident', 'vehicle_id', 'status'];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'vehicle_id');
    }
}

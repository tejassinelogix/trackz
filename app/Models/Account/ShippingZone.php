<?php declare(strict_types = 1);

namespace App\Models\Account;

use Delight\Cookie\Cookie;
use App\Models\Country;
use App\Models\Zone;
use PDO;

class ShippingZone
{
    // Contains Resources
    private $db;
    private $countryCodes = [
        'US' => 223, 
        'CA' => 38, 
        'GB' => 222, 
        'AU' => 13
    ];
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * find - Find shipping zone by ID
     *
     * @param  $id   - ID of zone
     * @return array - Shipping zone
    */
    public function find($id)
    {
        $query = 'SELECT * FROM ShippingZone WHERE Id = :id';
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * findByStore - Find all shipping zones tied to store
     *
     * @param  $memberId  - ID of store
     * @return array - Shipping zones
    */
    public function findByStore($storeId)
    {
        $query = 'SELECT * FROM ShippingZone WHERE StoreId = :storeId';
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':storeId', $storeId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * belongsToMember - Check if shipping zone belongs to member
     *
     * @param $zoneId - Shipping zone ID
     * @param $memberId - Member ID
     * @return bool
    */
    public function belongsToMember($zoneId, $memberId)
    {
        // TODO: Pre-prepare these queries to reduce overhead
        
        $query = 'SELECT StoreId FROM ShippingZone WHERE Id = :zoneId';
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':zoneId', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $storeId = $stmt->fetch(PDO::FETCH_ASSOC)['StoreId'];

        if ($storeId == NULL)
        {
            return false;
        }
        else
        {
            $query = 'SELECT MemberId FROM Store WHERE Id = :storeId';
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':storeId', $storeId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC)['MemberId'];
            return $result == $memberId;
        }
    }

    /**
     *  getStore - Get ID of store that the zone belongs to
     * 
     *  @param $zoneId - Zone ID
     *  @return 
     */
    public function getStoreID($zoneId)
    {
        $query = 'SELECT StoreId FROM ShippingZone WHERE Id = :id';
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch()['StoreId'];
    }

    /**
     *  create - Create a shipping zone
     * 
     *  @param  $data - Array containing zone information
     *  @return bool  - Indicates success
     */
    public function create(array $data)
    {
        $query =  'INSERT INTO ShippingZone (StoreId, `Name`) ';
        $query .= 'VALUES (:storeId, :name)';
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':storeId', $data['StoreId'], PDO::PARAM_INT);
        $stmt->bindParam(':name', $data['Name']);
        return $stmt->execute();
    }

    /**
     *  delete - Delete a shipping zone
     * 
     *  @param  $id  - Zone ID
     *  @return bool - Indicates success
     */
    public function delete($id)
    {
        $query = 'DELETE FROM ShippingZone WHERE Id = :id';
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     *  update - Update a shipping zone
     * 
     *  @param  $data - Zone data
     *  @return bool  - Indicates success
     */
    public function update($data)
    {
        $query = 'UPDATE ShippingZone SET `Name` = :_name WHERE Id = :id';
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $data['Id'], PDO::PARAM_INT);
        $stmt->bindParam(':_name', $data['Name'], PDO::PARAM_STR);
        return $stmt->execute();
    }

    /**
     *  getBulkCountryAssignment - Get bulk assignment to country if it exists
     * 
     *  @param $storeId - ID of country
     *  @return array
     */
    public function getBulkCountryAssignment($storeId, $countryId)
    {
        $query = 'SELECT * FROM ShippingZoneToRegion INNER JOIN ShippingZone ON ShippingZone.Id = ShippingZoneToRegion.ZoneId
                  WHERE ShippingZone.StoreId = :storeId
                  AND ShippingZoneToRegion.CountryId = :countryId
                  AND ShippingZoneToRegion.StateId IS NULL
                  AND ShippingZoneToRegion.ZipCodeMin IS NULL
                  AND ShippingZoneToRegion.ZipCodeMax IS NULL';
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':storeId', $storeId, PDO::PARAM_INT);
        $stmt->bindParam(':countryId', $countryId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     *  getBulkCountryAssignmentMap - Get mapping of countries to bulk assigned shipping zones
     * 
     *  @param $storeId - ID of store
     *  @return array
     */
    public function getBulkCountryAssignmentMap($storeId)
    {
        $countries = (new Country($this->db))->all();
        $map = [];

        $query = 'SELECT * FROM ShippingZone INNER JOIN ShippingZoneToRegion ON ShippingZoneToRegion.ZoneId = ShippingZone.Id
                  WHERE ShippingZone.StoreId = :storeId AND ShippingZoneToRegion.CountryId = :countryId AND ShippingZoneToRegion.StateId IS NULL
                  AND ShippingZoneToRegion.ZipCodeMin IS NULL AND ShippingZoneToRegion.ZipCodeMax IS NULL';

        $stmt = $this->db->prepare($query);

        foreach ($countries as $country)
        {
            $stmt->bindParam(':storeId', $storeId, PDO::PARAM_INT);
            $stmt->bindParam(':countryId', $country['Id'], PDO::PARAM_INT);
            $stmt->execute();
            $zone = $stmt->fetch(PDO::FETCH_ASSOC);

            if (boolval($zone)) {
                $map[json_encode($country)] = $zone;
            }
            else {
                $map[json_encode($country)] = null;
            }
        }

        return $map;
    }

    /**
     *  getBulkStateAssignments - Get mapping of states to bulk assigned shipping zones
     * 
     *  @param $storeId - ID of store
     *  @param $countryId - ID of country
     *  @return array
     */
    public function getStateAssignmentMap($storeId, $countryId)
    {
        $states = (new Country($this->db))->getStates($countryId);
        $map = [];

        /* I would delegate these queries to separate functions but doing so would hurt performance,
           as the same query template would have to be sent repeatedly
        */
        $bulkCountryQuery = 'SELECT * FROM ShippingZone INNER JOIN ShippingZoneToRegion ON ShippingZoneToRegion.ZoneId = ShippingZone.Id
                             WHERE ShippingZone.StoreId = :storeId AND ShippingZoneToRegion.CountryId = :countryId AND ShippingZoneToRegion.StateId IS NULL
                             AND ShippingZoneToRegion.ZipCodeMin IS NULL AND ShippingZoneToRegion.ZipCodeMax IS NULL';

        $stmt = $this->db->prepare($bulkCountryQuery);
        $stmt->bindParam(':storeId', $storeId, PDO::PARAM_INT);
        $stmt->bindParam(':countryId', $countryId, PDO::PARAM_INT);
        $stmt->execute();
        $countryAssignment = $stmt->fetch(PDO::FETCH_ASSOC);

        $bulkStateQuery = 'SELECT * FROM ShippingZone INNER JOIN ShippingZoneToRegion ON ShippingZoneToRegion.ZoneId = ShippingZone.Id
                           WHERE ShippingZone.StoreId = :storeId AND ShippingZoneToRegion.StateId = :stateId AND ShippingZoneToRegion.ZipCodeMin IS NULL 
                           AND ShippingZoneToRegion.ZipCodeMax IS NULL';

        $stmt = $this->db->prepare($bulkStateQuery);

        foreach ($states as $state)
        {
            $stmt->bindParam(':storeId', $storeId, PDO::PARAM_INT);
            $stmt->bindParam(':stateId', $state['Id'], PDO::PARAM_INT);
            $stmt->execute();
            $stateAssignment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (boolval($stateAssignment)) {
                $map[json_encode($state)] = $stateAssignment;
            }
            else {
                if (boolval($countryAssignment)) {
                    $map[json_encode($state)] = $countryAssignment;
                }
                else {
                    $map[json_encode($state)] = null;
                }
            }
        }
        
        return $map;
    }

    /**
     *  bulkAssignToCountry - Assign a shipping zone to an entire region
     * 
     *  @param $zoneId - Shipping zone ID
     *  @param $countryID - ID of country to assign zone to
     *  @return bool - Indicates success
     */
    public function deleteBulkCountryAssignment($storeId, $countryId)
    {
        $currentAssignment = $this->getBulkCountryAssignment($storeId, $countryId);
        $query = 'DELETE FROM ShippingZoneToRegion WHERE ZoneId = :zoneId AND CountryId = :countryId';
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':zoneId', $currentAssignment['ZoneId'], PDO::PARAM_INT);
        $stmt->bindParam(':countryId', $countryId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     *  bulkAssignToCountry - Assign a shipping zone to an entire region
     * 
     *  @param $zoneId - Shipping zone ID
     *  @param $countryIDs - Array of countries to assign zone to
     *  @return bool - Indicates success
     */
    public function bulkAssignToCountry($zoneId, array $countryIDs)
    {
        $storesZones = $this->findByStore($this->getStoreID($zoneId));
        $zoneIDs = array_map(function($zone) {
            return $zone['Id'];
        }, $storesZones);
        $idStr = implode(",", $zoneIDs);

        // Delete redundant specific entries before bulk assigning to save space
        $query = 'DELETE FROM ShippingZoneToRegion WHERE CountryId = :countryId AND ZoneId IN (' . $idStr . ');';
        $query .= 'INSERT INTO ShippingZoneToRegion (ZoneId, CountryId) VALUES (:zoneId, :countryId)';
        $stmt = $this->db->prepare($query);

        foreach ($countryIDs as $countryId)
        {
            $stmt->bindParam(':zoneId', $zoneId, PDO::PARAM_INT);
            $stmt->bindParam(':countryId', $countryId, PDO::PARAM_INT);
            if (!$stmt->execute())
                return false;
        }

        return true;
    }

    /**
     *  bulkAssignToState - Assign a shipping zone to an entire state
     * 
     *  @param $zoneId - ID of shipping zone
     *  @param $countryId - ID of country
     *  @param $stateId - ID of state
     *  @return bool - Indicates succcess
     */
    public function bulkAssignToState($zoneId, $countryId, $stateId)
    {
        $bulkCountryQuery = 'SELECT Id FROM ShippingZoneToRegion WHERE ZoneId = :zoneId AND CountryId = :countryId
                             AND StateId IS NULL AND ZipCodeMin IS NULL AND ZipCodeMax IS NULL';

        $stmt = $this->db->prepare($bulkCountryQuery);
        $stmt->bindParam(':zoneId', $zoneId, PDO::PARAM_INT);
        $stmt->bindParam(':countryId', $countryId, PDO::PARAM_INT);
        $stmt->execute();
        $bulkCountryAssignment = $stmt->fetch(PDO::FETCH_ASSOC);

        $storesZones = $this->findByStore($this->getStoreID($zoneId));
        $zoneIDs = array_map(function($zone) {
            return $zone['Id'];
        }, $storesZones);
        $idStr = implode(",", $zoneIDs);

        if (boolval($bulkCountryAssignment)) {
            $query = 'DELETE FROM ShippingZoneToRegion WHERE StateId = :stateId AND ZoneId IN (' . $idStr . ')';
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':stateId', $stateId, PDO::PARAM_INT);
            return $stmt->execute();
        }
        else {
            $query = 'SELECT Id FROM ShippingZoneToRegion WHERE StateId = :stateId AND ZoneId = :zoneId';
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':zoneId', $zoneId, PDO::PARAM_INT);
            $stmt->bindParam(':stateId', $stateId, PDO::PARAM_INT);
            $stmt->execute();
            $assignmentAlreadyExists = boolval($stmt->fetch(PDO::FETCH_ASSOC));

            if ($assignmentAlreadyExists) {
                return true;
            }
            else {
                $query = 'INSERT INTO ShippingZoneToRegion (ZoneId, CountryId, StateId) VALUES (:zoneId, :countryId, :stateId)';
                $stmt = $this->db->prepare($query);
                $stmt->bindParam(':zoneId', $zoneId, PDO::PARAM_INT);
                $stmt->bindParam(':countryId', $countryId, PDO::PARAM_INT);
                $stmt->bindParam(':stateId', $stateId, PDO::PARAM_INT);
                return $stmt->execute();
            }
        }
    }

    /**
     *  getZipCodeAssignments - Get zip code level assignments for state
     * 
     *  @param $stateId - ID of state
     *  @param $storeId - ID of store
     *  @return array of assignments
     */
    public function getZipCodeAssignments($stateId, $storeId)
    {
        $query = 'SELECT ShippingZoneToRegion.Id, ShippingZoneToRegion.ZoneId, ShippingZone.Name, ShippingZoneToRegion.ZipCodeMin, ShippingZoneToRegion.ZipCodeMax FROM ShippingZoneToRegion 
                  INNER JOIN ShippingZone ON ShippingZoneToRegion.ZoneId = ShippingZone.Id 
                  WHERE ShippingZone.StoreId = :storeId
                  AND ShippingZoneToRegion.StateId = :stateId
                  AND ShippingZoneToRegion.ZipCodeMin IS NOT NULL
                  AND ShippingZoneToRegion.ZipCodeMax IS NOT NULL
                  ORDER BY ShippingZoneToRegion.ZipCodeMin';

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':storeId', $storeId, PDO::PARAM_INT);
        $stmt->bindParam(':stateId', $stateId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     *  assignToZipRange - Assign shipping zone to zip code range
     * 
     *  @param $zoneId - ID of shipping zone
     *  @param $stateId - ID of state
     *  @param $zipCodeMin - Lower bound for zip code range
     *  @param $zipCodeMax - Upper bound for zip code range
     *  @return array of conflicting entries (if any exist)
     *  @return bool to indicate success of insert
     */
    public function assignToZipRange($zoneId, $stateId, $zipCodeMin, $zipCodeMax)
    {
        $query = 'SELECT Id FROM ShippingZoneToRegion WHERE StateId = :stateId AND ((:zipMin <= ZipCodeMin AND :zipMax >= ZipCodeMax) OR (:zipMin <= ZipCodeMax AND :zipMax >= ZipCodeMax) OR (:zipMin >= ZipCodeMin AND :zipMax <= ZipCodeMax) OR (:zipMin <= ZipCodeMin AND :zipMax >= ZipCodeMin))';
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':stateId', $stateId, PDO::PARAM_INT);
        $stmt->bindParam(':zipMin', $zipCodeMin, PDO::PARAM_INT);
        $stmt->bindParam(':zipMax', $zipCodeMax, PDO::PARAM_INT);
        $stmt->execute();
        $conflicts = array_map(function($conflict) { return $conflict['Id']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        if ($conflicts) {
            return $conflicts;
        }
        else {
            $countryId = (new Zone($this->db))->getCountryId($stateId);
            $query = 'INSERT INTO ShippingZoneToRegion (ZoneId, CountryId, StateId, ZipCodeMin, ZipCodeMax) VALUES (:zoneId, :countryId, :stateId, :zipMin, :zipMax)';
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':zoneId', $zoneId, PDO::PARAM_INT);
            $stmt->bindParam(':countryId', $countryId, PDO::PARAM_INT);
            $stmt->bindParam(':stateId', $stateId, PDO::PARAM_INT);
            $stmt->bindParam(':zipMin', $zipCodeMin, PDO::PARAM_INT);
            $stmt->bindParam(':zipMax', $zipCodeMax, PDO::PARAM_INT);
            return $stmt->execute();
        }
    }

    /**
     *  deleteZipCodeAssignment - Delete zip code level shipping zone assignment
     * 
     *  @param $assignmentId - ID of assignment
     *  @return bool to indicate success
     */
    public function deleteZipCodeAssignment($assignmentId)
    {
        $query = 'DELETE FROM ShippingZoneToRegion WHERE Id = :id';
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $assignmentId, PDO::PARAM_INT);
        return $stmt->execute();
    }
}

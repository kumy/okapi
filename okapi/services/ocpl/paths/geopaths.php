<?php

namespace okapi\services\ocpl\paths\geopaths;

use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\Settings;
use okapi\ParamMissing;
use okapi\Db;
use ArrayObject;

require_once('geopath_static.inc.php');

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 1
        );
    }

    private static $valid_field_names = array('uuid', 'name', 'names',
        'location', 'type', 'status', 'mentor', 'authors', 'url', 'image_url',
        'description','descriptions','geocaches_total','geocaches_found',
        'geocaches_found_ratio','min_founds_to_complete','min_ratio_to_complete',
        'my_completed_status','completed_count','last_completed','date_created',
        'gplog_uuids');


    public static function call(OkapiRequest $request)
    {
        $path_uuids = $request->get_parameter('path_uuids');
        if ($path_uuids === null) throw new ParamMissing('path_uuids');
        if ($path_uuids === "")
        {
            # Issue 106 requires us to allow empty list of cache codes to be passed into this method.
            # All of the queries below have to be ready for $path_uuid to be empty!
            $path_uuids = array();
        }
        else
            $path_uuids = explode("|", $path_uuids);

        if ((count($path_uuids) > 100) && (!$request->skip_limits))
            throw new InvalidParam('path_uuids', "Maximum allowed number of referenced ".
                "caches is 100. You provided ".count($path_uuids)." geopaths uuids.");
        if (count($path_uuids) != count(array_unique($path_uuids)))
            throw new InvalidParam('path_uuid', "Duplicate uuids detected (make sure each geopath is referenced only once).");

        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $langpref .= "|".Settings::get('SITELANG');
        $langpref = explode("|", $langpref);

        $fields = $request->get_parameter('fields');
        if (!$fields) $fields = "uuid|name|type|status|url";
        $fields = explode("|", $fields);
        foreach ($fields as $field)
            if (!in_array($field, self::$valid_field_names))
                throw new InvalidParam('fields', "'$field' is not a valid field code.");

        $rs = Db::query("
            select
                pt.id as uuid, pt.name, pt.centerLatitude as latitude,
                pt.centerLongitude as longitude,
                pt.type, pt.status, pt.dateCreated as date_created, pt.cacheCount as geocaches_total,
                pt.description, pt.image as image_url, pt.perccentRequired as min_ratio_to_complete,
                pt.conquestedCount as completed_count
            from
                PowerTrail pt
            where
                id in ('".implode("','", array_map('\okapi\Db::escape_string', $path_uuids))."')
                and pt.status in (1,2,3)
        "); //TODO: status

        while ($row = Db::fetch_assoc($rs))
        {
            $entry = array();
            foreach ($fields as $field)
            {
                switch ($field)
                {
                    case 'uuid': $entry['uuid'] = $row['uuid']; break;
                    case 'name': $entry['name'] = $row['name']; break;
                    case 'names': $entry['names'] = array(Settings::get('SITELANG') => $row['name']); break; // for the future
                    case 'location': $entry['location'] = round($row['latitude'], 6)."|".round($row['longitude'], 6); break;
                    case 'type': $entry['type'] = GeopathStatics::geopath_type_id2name($row['type']); break;
                    case 'status': $entry['status'] = GeopathStatics::geopath_status_id2name($row['status']); break;
                    case 'mentor':
                        $entry['mentor'] = null;
                        /* continued later */
                        break;
                    case 'authors':
                        $entry['authors'] = array();
                        /* continued later */
                        break;
                    case 'url':
                        // str_replace is temporary - https://forum.opencaching.pl/viewtopic.php?f=6&t=7089&p=136968#p136968
                        $entry['url'] = (
                            str_replace("https://", "http://", Settings::get('SITE_URL')).
                            "powerTrail.php?ptAction=showSerie&ptrail=".$row['uuid']
                            ); //TODO: will be changed soon
                        break;
                    case 'image_url': $entry['image_url'] = $row['image_url']; break;
                    case 'description': $entry['description'] = Okapi::fix_oc_html($row['description'], Okapi::OBJECT_TYPE_GEOPATH); break;
                    case 'descriptions':
                        $entry['descriptions'] =
                            array(Settings::get('SITELANG') => Okapi::fix_oc_html($row['description'], Okapi::OBJECT_TYPE_GEOPATH));
                        break; // for the future
                    case 'geocaches_total': $entry['image_url'] = $row['image_url']; break;
                    case 'geocaches_found': /* handled separately */ break;
                    case 'geocaches_found_ratio': /* handled separately */ break;
                    case 'min_founds_to_complete': /* handled separately */ break;
                    case 'min_ratio_to_complete':
                        $entry['min_ratio_to_complete'] = $row['min_ratio_to_complete'];
                        break;
                    case 'my_completed_status': /* handled separately */ break;
                    case 'completed_count':
                        $entry['completed_count'] = $row['completed_count'];
                        break;
                    case 'last_completed': /* handled separately */ break;
                    case 'date_created':
                        $entry['date_created'] = date('c', strtotime($row['date_created'])); break;
                        break;
                    case 'gplog_uuids':
                        $entry['gplog_uuids'] = array();
                        /* continued later */
                        break;
                    default: throw new Exception("Missing field case: ".$field);
                }
            }
            $results[$row['uuid']] = $entry;
        }
        Db::free_result($rs);

        # mentor & authors

        $process_authors = in_array('authors', $fields);
        $process_mentor = in_array('mentor', $fields);

        if ( ( $process_authors || $process_mentor ) && count($results) > 0)
        {
            $privileges = array();
            if( $process_authors ) $privileges[] = 1;
            if( $process_mentor ) $privileges[] = 2;


            $rs = Db::query("
                select user_id, uuid, username, pto.PowerTrailId as path_uuid, pto.privileages as privileges
                from
                    PowerTrail_owners as pto
                    join user as u on u.user_id = pto.userId
                where
                    pto.PowerTrailId in ('".implode("','", array_map('\okapi\Db::escape_string', array_keys($results)))."')
                    and privileages in ('".implode("','", array_map('\okapi\Db::escape_string', array_values($privileges)))."')
            ");

            while ($row = Db::fetch_assoc($rs))
            {
                if( $process_authors )
                {
                    /* every mentor is also an author */
                    $results[$row['path_uuid']]['authors'][] = array(
                        'uuid' => $row['uuid'],
                        'username' => $row['username'],
                        'profile_url' => Settings::get('SITE_URL')."viewprofile.php?userid=".$row['user_id']
                    );
                }

                if( $process_mentor && $row['privileges'] == 2 )
                {
                    $results[$row['path_uuid']]['mentor'] = array(
                        'uuid' => $row['uuid'],
                        'username' => $row['username'],
                        'profile_url' => Settings::get('SITE_URL')."viewprofile.php?userid=".$row['user_id']
                    );
                }
            }
            Db::free_result($rs);
        }

        # geocaches_found
        # geocaches_found_ratio
        # min_founds_to_complete
        # my_completed_status
        # last_completed

            #TODO:...

        # gplog_uuids

        if ( in_array('gplog_uuids', $fields) && count($results) > 0)
        {
            $rs = Db::query("
                select id, PowerTrailId as path_uuid
                from PowerTrail_comments
                where PowerTrailId in ('".implode("','", array_map('\okapi\Db::escape_string', array_keys($results)))."')
            ");

            while ($row = Db::fetch_assoc($rs))
            {
                if( $process_authors )
                    $results[$row['path_uuid']]['gplog_uuids'][] = $row['id'];

            }
            Db::free_result($rs);
        }

        # Check which geopaths were not found and mark them with null.
        foreach ($path_uuids as $path_uuid)
            if (!isset($results[$path_uuid]))
                $results[$path_uuid] = null;


        # Order the results in the same order as the input uuids were given.
        # This might come in handy for languages which support ordered dictionaries
        # (especially with conjunction with the search_and_retrieve method).
        # See issue#97. PHP dictionaries (assoc arrays) are ordered structures,
        # so we just have to rewrite it (sequentially).

        $ordered_results = new ArrayObject();
        foreach ($path_uuids as $path_uuid)
            $ordered_results[$path_uuid] = $results[$path_uuid];


        return Okapi::formatted_response($request, $ordered_results);
    }
}

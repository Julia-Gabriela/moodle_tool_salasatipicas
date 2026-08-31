<?php
namespace tool_salasatipicas\privacy;
defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_subsystem_link('core_log', [], 'privacy:metadata:core_log');
        $collection->add_database_table('tool_atyp_dep_hist', [
            'studentuserid'=>'dep_privacy:metadata:history:studentuserid','teacheruserid'=>'dep_privacy:metadata:history:teacheruserid',
            'studentname'=>'dep_privacy:metadata:history:studentname','teachername'=>'dep_privacy:metadata:history:teachername',
            'studentusername'=>'dep_privacy:metadata:history:studentusername','teacherusername'=>'dep_privacy:metadata:history:teacherusername',
            'createdby'=>'dep_privacy:metadata:history:createdby','processnumber'=>'dep_privacy:metadata:history:processnumber',
            'subject'=>'dep_privacy:metadata:history:subject','period'=>'dep_privacy:metadata:history:period',
        ], 'dep_privacy:metadata:history');
        $collection->add_database_table('tool_atyp_dep_part', [
            'historyid'=>'dep_privacy:metadata:participant:historyid','userid'=>'dep_privacy:metadata:participant:userid',
            'username'=>'dep_privacy:metadata:participant:username','fullname'=>'dep_privacy:metadata:participant:fullname',
            'participanttype'=>'dep_privacy:metadata:participant:type','roleid'=>'dep_privacy:metadata:participant:roleid',
        ], 'dep_privacy:metadata:participant');
        $collection->add_database_table('tool_atyp_rea_hist', [
            'studentdisplayname'=>'rea_privacy:metadata:history:studentdisplayname','courseprogram'=>'rea_privacy:metadata:history:courseprogram',
            'phone'=>'rea_privacy:metadata:history:phone','contactemail'=>'rea_privacy:metadata:history:contactemail',
            'subjectsjson'=>'rea_privacy:metadata:history:subjectsjson','createdby'=>'rea_privacy:metadata:history:createdby',
        ], 'rea_privacy:metadata:history');
        $collection->add_database_table('tool_atyp_rea_part', [
            'historyid'=>'rea_privacy:metadata:participant:historyid','userid'=>'rea_privacy:metadata:participant:userid',
            'username'=>'rea_privacy:metadata:participant:username','fullname'=>'rea_privacy:metadata:participant:fullname',
            'participanttype'=>'rea_privacy:metadata:participant:type','roleid'=>'rea_privacy:metadata:participant:roleid',
        ], 'rea_privacy:metadata:participant');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB; $list=new contextlist();
        $exists=$DB->record_exists_select('tool_atyp_dep_hist','studentuserid=? OR teacheruserid=? OR createdby=?',[$userid,$userid,$userid])
            || $DB->record_exists('tool_atyp_dep_part',['userid'=>$userid])
            || $DB->record_exists('tool_atyp_rea_part',['userid'=>$userid])
            || $DB->record_exists('tool_atyp_rea_hist',['createdby'=>$userid]);
        if($exists){$list->add_system_context();} return $list;
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB; $ctx=\context_system::instance();
        if(!in_array($ctx->id,$contextlist->get_contextids(),true)){return;}
        $userid=(int)$contextlist->get_user()->id; $data=(object)['dependencias'=>[],'reas'=>[]];
        $depids=$DB->get_fieldset_select('tool_atyp_dep_part','historyid','userid=?',[$userid]);
        $depids=array_merge($depids,$DB->get_fieldset_select('tool_atyp_dep_hist','id','studentuserid=? OR teacheruserid=? OR createdby=?',[$userid,$userid,$userid]));
        $depids=array_values(array_unique(array_map('intval',$depids)));
        if($depids){ foreach($DB->get_records_list('tool_atyp_dep_hist','id',$depids,'timecreated ASC') as $r){$data->dependencias[]=(object)['processo'=>$r->processnumber,'disciplina'=>$r->subject,'periodo'=>$r->period,'status'=>$r->status,'criadoem'=>transform::datetime($r->timecreated)];}}
        $reaids=$DB->get_fieldset_select('tool_atyp_rea_part','historyid','userid=?',[$userid]);
        $reaids=array_merge($reaids,$DB->get_fieldset_select('tool_atyp_rea_hist','id','createdby=?',[$userid]));
        $reaids=array_values(array_unique(array_map('intval',$reaids)));
        if($reaids){ foreach($DB->get_records_list('tool_atyp_rea_hist','id',$reaids,'timecreated ASC') as $r){$data->reas[]=(object)['aluno'=>$r->studentdisplayname,'curso'=>$r->courseprogram,'periodo'=>$r->period,'status'=>$r->status,'criadoem'=>transform::datetime($r->timecreated)];}}
        writer::with_context($ctx)->export_data([get_string('pluginname','tool_salasatipicas')],$data);
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB; if($context->contextlevel!==CONTEXT_SYSTEM){return;}
        foreach(['tool_atyp_dep_part','tool_atyp_dep_hist','tool_atyp_rea_part','tool_atyp_rea_hist'] as $table){$DB->delete_records($table);}
    }
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $ctx=\context_system::instance(); if(!in_array($ctx->id,$contextlist->get_contextids(),true)){return;} self::anonymise([$contextlist->get_user()->id]);
    }
    public static function get_users_in_context(userlist $userlist): void {
        global $DB; if($userlist->get_context()->contextlevel!==CONTEXT_SYSTEM){return;}
        $sql="SELECT userid FROM {tool_atyp_dep_part} WHERE userid>0 UNION SELECT studentuserid FROM {tool_atyp_dep_hist} WHERE studentuserid>0 UNION SELECT teacheruserid FROM {tool_atyp_dep_hist} WHERE teacheruserid>0 UNION SELECT createdby FROM {tool_atyp_dep_hist} WHERE createdby>0 UNION SELECT userid FROM {tool_atyp_rea_part} WHERE userid>0 UNION SELECT createdby FROM {tool_atyp_rea_hist} WHERE createdby>0";
        $userlist->add_users($DB->get_fieldset_sql($sql));
    }
    public static function delete_data_for_users(approved_userlist $userlist): void { if($userlist->get_context()->contextlevel===CONTEXT_SYSTEM){self::anonymise($userlist->get_userids());} }
    private static function anonymise(array $ids): void {
        global $DB; $ids=array_values(array_unique(array_filter(array_map('intval',$ids)))); if(!$ids){return;}
        [$sql,$p]=$DB->get_in_or_equal($ids,SQL_PARAMS_NAMED,'u'); $DB->delete_records_select('tool_atyp_dep_part',"userid {$sql}",$p);
        [$sql2,$p2]=$DB->get_in_or_equal($ids,SQL_PARAMS_NAMED,'r'); $DB->delete_records_select('tool_atyp_rea_part',"userid {$sql2}",$p2);
        // Preserve operational records while removing direct account links for creators/legacy dependency fields.
        foreach($ids as $id){
            $DB->set_field_select('tool_atyp_dep_hist','createdby',0,'createdby=?',[$id]);
            $DB->set_field_select('tool_atyp_dep_hist','studentuserid',0,'studentuserid=?',[$id]);
            $DB->set_field_select('tool_atyp_dep_hist','teacheruserid',0,'teacheruserid=?',[$id]);
            $DB->set_field_select('tool_atyp_rea_hist','createdby',0,'createdby=?',[$id]);
        }
    }
}

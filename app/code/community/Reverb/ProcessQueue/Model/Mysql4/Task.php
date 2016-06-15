<?php
/**
 * Author: Sean Dunagan
 * Created: 8/13/15
 * Class Reverb_ProcessQueue_Model_Mysql_Task
 */

class Reverb_ProcessQueue_Model_Mysql4_Task extends Mage_Core_Model_Mysql4_Abstract
{
    public function _construct()
    {
        $this->_init('reverb_process_queue/task','task_id');
    }

    public function selectForUpdate(Reverb_ProcessQueue_Model_Task_Interface $taskObject)
    {
        $task_id = $taskObject->getId();
        if (empty($task_id))
        {
            // $taskObject must be an existing/loaded object in order to lock it
            return false;
        }

        $select = $this->_getWriteAdapter()->select()
                        ->from(array('process_queue' => $this->getMainTable()))
                        ->where('task_id=?', $task_id)
                        ->where('status=?', Reverb_ProcessQueue_Model_Task::STATUS_PROCESSING)
                        ->forUpdate(true);

        $selected = $this->_getWriteAdapter()->fetchOne($select);
        return $selected;
    }

    public function attemptUpdatingRowAsProcessing(Reverb_ProcessQueue_Model_Task_Interface $taskObject)
    {
        $task_id = $taskObject->getId();
        if (empty($task_id))
        {
            // $taskObject must be an existing/loaded object in order to lock it
            return false;
        }
        // Status here can be PENDING or ERROR
        $current_status = $taskObject->getStatus();
        if (Reverb_ProcessQueue_Model_Task::STATUS_PROCESSING == $current_status)
        {
            // Assume another execution thread is actively processing this task
            return false;
        }
        $current_gmt_datetime = Mage::getSingleton('core/date')->gmtDate();

        // First, attempt to update the row based on id and status. If no rows are updated, another thread has already
        //  begun processing this row. Also we want to do this outside of any transactions so that we know other mysql
        //  connections will see that this row is already processing

        $update_bind_array = array('status' => Reverb_ProcessQueue_Model_Task::STATUS_PROCESSING,
                                    'status_message' => null,
                                    'last_executed_at' => $current_gmt_datetime);
        $where_conditions_array = array('task_id=?' => $task_id,
                                        'status=?' => $current_status,
                                        // As an additional safety measure, don't update any rows already in processing state
                                        'status<>?' => Reverb_ProcessQueue_Model_Task::STATUS_PROCESSING);

        $rows_updated = $this->_getWriteAdapter()->update($this->getMainTable(), $update_bind_array, $where_conditions_array);
        return $rows_updated;
    }

    public function setExecutionStatusForTask($execution_status, Reverb_ProcessQueue_Model_Task_Interface $taskObject, $status_message = null)
    {
        $task_id = $taskObject->getId();
        if (empty($task_id))
        {
            // TODO Some logging here
            return 0;
        }

        if ($taskObject->isStatusValid($execution_status))
        {
            $update_bind_array = array('status' => $execution_status);
            if (!is_null($status_message))
            {
                $update_bind_array['status_message'] = $status_message;
            }
            $task_id = $taskObject->getId();
            $where_conditions_array = array('task_id=?' => $task_id);
            $rows_updated = $this->_getWriteAdapter()->update($this->getMainTable(), $update_bind_array, $where_conditions_array);
            return $rows_updated;
        }

        // TODO Log error in this case
        return 0;
    }

    /**
     * @param null|string|array $task_code
     * @param null|string $last_executed_date - Expected to be a date in 'Y-m-d H:i:s' format
     * @return int - Number of rows deleted
     */
    public function deleteSuccessfulTasks($task_code = null, $last_executed_date = null)
    {
        $where_condition_array = array('status=?' => Reverb_ProcessQueue_Model_Task::STATUS_COMPLETE);
        if (!empty($task_code))
        {
            if (!is_array($task_code))
            {
                $task_code = array($task_code);
            }
            $where_condition_array['code in (?)'] = $task_code;
        }
        if (!empty($last_executed_date))
        {
            $where_condition_array['last_executed_at < ?'] = $last_executed_date;
        }
        $rows_deleted = $this->_getWriteAdapter()->delete($this->getMainTable(), $where_condition_array);
        return $rows_deleted;
    }

    public function deleteAllTasks($task_code = null, $statuses_to_delete = array())
    {
        if(!empty($task_code))
        {
            if (!is_array($task_code))
            {
                $task_code = array($task_code);
            }

            $where_condition_array = array('code in (?)' => $task_code);

            if (!empty($statuses_to_delete))
            {
                $where_condition_array['status in (?)'] = $statuses_to_delete;
            }

            $rows_deleted = $this->_getWriteAdapter()->delete($this->getMainTable(), $where_condition_array);
        }
        else
        {
            $rows_deleted = $this->_getWriteAdapter()->delete($this->getMainTable());
        }

        return $rows_deleted;
    }

    public function setTaskAsCompleted(Reverb_ProcessQueue_Model_Task_Interface $taskObject, $success_message = null)
    {
        return $this->setExecutionStatusForTask(Reverb_ProcessQueue_Model_Task::STATUS_COMPLETE, $taskObject, $success_message);
    }

    public function setTaskAsErrored(Reverb_ProcessQueue_Model_Task_Interface $taskObject, $error_message = null)
    {
        return $this->setExecutionStatusForTask(Reverb_ProcessQueue_Model_Task::STATUS_ERROR, $taskObject, $error_message);
    }

    public function updateLastExecutedAtToCurrentTime(array $task_ids)
    {
        $current_gmt_datetime = Mage::getSingleton('core/date')->gmtDate();
        $update_bind_array = array('last_executed_at' => $current_gmt_datetime);
        $where_conditions_array = array('task_id IN (?)' => $task_ids);
        $rows_updated = $this->_getWriteAdapter()->update($this->getMainTable(), $update_bind_array, $where_conditions_array);
        return $rows_updated;
    }
}

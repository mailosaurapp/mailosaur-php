<?php

namespace Mailosaur\Operations;

use Mailosaur\Models\MailosaurException;
use Mailosaur\Models\Message;
use Mailosaur\Models\MessageListResult;
use Mailosaur\Models\SearchCriteria;

class Messages extends AOperation
{
    /**
     * <strong>Retrieve a message using search criteria.</strong>
     * <p>Returns as soon as a message matching the specified search criteria is found. This is the most efficient method of looking up a message.</p>
     *
     * @param string         $server         The identifier of the server hosting the message.
     * @param SearchCriteria $searchCriteria The search criteria to use in order to find a match.
     * @param int            $timeout        Specify how long to wait for a matching result (in milliseconds).
     * @param \DateTime      $receivedAfter  Limits results to only messages received after this date/time.
     *
     * @return Message
     * @throws MailosaurException
     * @see     https://mailosaur.com/docs/api/#operation/Messages_Get Retrieve a message docs
     * @example https://mailosaur.com/docs/api/#operation/Messages_Get
     */
    public function get($server, SearchCriteria $searchCriteria, $timeout = 10000, DateTime $receivedAfter = null)
    {
        if (strlen($server) != 8) {
            throw new MailosaurException('Must provide a valid Server ID.', 'invalid_request');
        }

        # Defaults receivedAfter to 1h
        if ($receivedAfter == null) {
            $datetime = new \DateTime();
            $datetime->sub(new \DateInterval('PT1H'));
        }

        $result = $this->search($server, $searchCriteria, 0, 1, $timeout, $receivedAfter);
        return $this->getById($result->items[0]->id);
    }

    /**
     * <strong>Retrieves the detail for a single email message.</strong>
     * <p>Simply supply the unique identifier for the required message.</p>
     *
     * @param $id string message id
     *
     * @return Message
     * @throws MailosaurException
     * @see     https://mailosaur.com/docs/api/#operation/Messages_Get Retrieve a message by ID docs
     * @example https://mailosaur.com/docs/api/#operation/Messages_Get
     */
    public function getById($id)
    {
        $message = $this->request('api/messages/' . urlencode($id));
        $message = json_decode($message);

        return new Message($message);
    }

    /**
     * <strong>Permanently deletes a message.</strong>
     * <p>This operation cannot be undone. Also deletes any attachments related to the message.</p>
     *
     * @param $id string
     *
     * @throws MailosaurException
     * @see     https://mailosaur.com/docs/api/#operation/Messages_Delete Delete a message docs
     * @example https://mailosaur.com/docs/api/#operation/Messages_Delete
     */
    public function delete($id)
    {
        $this->request('api/messages/' . urlencode($id), array(CURLOPT_CUSTOMREQUEST => 'DELETE'));
    }

    /**
     * <strong>List all messages</strong><br/>
     *
     * @param string $server       The identifier of the server hosting the messages.
     * @param int    $page         Used in conjunction with itemsPerPage to support pagination.
     * @param int    $itemsPerPage A limit on the number of results to be returned per page.
     *                             Can be set between 1 and 1000 items, the default is 50.
     * @param \DateTime      $receivedAfter  Limits results to only messages received after this date/time.
     *
     * @return MessageListResult
     * @throws MailosaurException
     * @see     https://mailosaur.com/docs/api/#operation/Messages_List List all messages
     * @example https://mailosaur.com/docs/api/#operation/Messages_List
     */
    public function all($server, $page = 0, $itemsPerPage = 50, \DateTime $receivedAfter = null)
    {
        $path = 'api/messages?' . http_build_query(array(
            'server' => $server,
            'page' => $page,
            'itemsPerPage' => $itemsPerPage,
            'receivedAfter' => ($receivedAfter != null) ? $receivedAfter->format(\DateTime::ATOM) : null
        ));

        $messagesResponse = $this->request($path);

        $messagesResponse = json_decode($messagesResponse);

        return new MessageListResult($messagesResponse);
    }

    /**
     * <strong>Delete all messages</strong>
     *
     * @param string $server The identifier of the server to be emptied.
     *
     * @throws MailosaurException
     * @see     https://mailosaur.com/docs/api/#operation/Messages_DeleteAll Delete all messages
     * @example https://mailosaur.com/docs/api/#operation/Messages_DeleteAll
     */
    public function deleteAll($server)
    {
        $this->request('api/messages?server=' . urlencode($server), array(CURLOPT_CUSTOMREQUEST => 'DELETE'));
    }

    /**
     * <strong>Search for messages</strong>
     * <p>Returns a list of messages matching the specified search criteria, in summary form.
     * The messages are returned sorted by received date, with the most recently-received messages appearing first.</p>
     *
     * @param string         $server         The identifier of the server hosting the messages.
     * @param SearchCriteria $searchCriteria Search criteria
     * @param int            $page           Used in conjunction with itemsPerPage to support pagination.
     * @param int            $itemsPerPage   A limit on the number of results to be returned per page.
     *                                       Can be set between 1 and 1000 items, the default is 50.
     * @param int            $timeout        Specify how long to wait for a matching result (in milliseconds).
     * @param \DateTime      $receivedAfter  Limits results to only messages received after this date/time.
     * @param bool           $errorOnTimeout When set to false, an error will not be throw if timeout is reached
     *                                       (default: true).
     *
     * @return MessageListResult
     * @throws MailosaurException
     */
    public function search($server, SearchCriteria $searchCriteria, $page = 0, $itemsPerPage = 50, $timeout = null, \DateTime $receivedAfter = null, $errorOnTimeout = true)
    {
        $payload = $searchCriteria->toJsonString();

        $path = 'api/messages/search?' . http_build_query(array(
            'server' => $server,
            'page' => $page,
            'itemsPerPage' => $itemsPerPage,
            'receivedAfter' => ($receivedAfter != null) ? $receivedAfter->format(\DateTime::ATOM) : null
        ));

        $pollCount = 0;
        $headers = [];
        $startTime = round(microtime(true) * 1000);

        while (true) {
            $messagesResponse = $this->request(
                $path,
                array(
                    CURLOPT_URL           => $this->client->getBaseUri() . $path,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS    => $payload,
                    CURLOPT_HTTPHEADER    => array('Content-Type:application/json', 'Content-Length: ' . strlen($payload)),
                    CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers)
                    {
                        $len = strlen($header);
                        $header = explode(':', $header, 2);
                        if (count($header) < 2) // ignore invalid headers
                        return $len;
            
                        $headers[strtolower(trim($header[0]))][] = trim($header[1]);
            
                        return $len;
                    }
                )
            );

            $messagesResponse = json_decode($messagesResponse);
            $result = new MessageListResult($messagesResponse);

            if ($timeout == null || $timeout == 0 || count($result->items) != 0) {
                return $result;
            }

            $delayPattern = isset($headers['x-ms-delay']) ?
                array_map('intval', explode(',', $headers['x-ms-delay'][0] ?: '1000'))
                : array(1000);

            $delay = $pollCount >= count($delayPattern) ?
                $delayPattern[count($delayPattern) - 1] :
                $delayPattern[$pollCount];

            $pollCount++;

            // Stop if timeout will be exceeded
            if ((round(microtime(true) * 1000) - $startTime) + $delay > $timeout) {
                if ($errorOnTimeout == false) {
                    return $result;
                }

                throw new MailosaurException("No matching messages found in time. By default, only messages received in the last hour are checked (use receivedAfter to override this).", "search_timeout");
            }

            sleep($delay / 1000);
        }
    }
}
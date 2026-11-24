<?php

namespace Kanboard\Plugin\Broadside\Controller;

use Closure;
use DomainException;
use InvalidArgumentException;
use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Broadside\Model\Access;
use Kanboard\Plugin\Broadside\Model\SubmissionModel;
use LengthException;
use OutOfBoundsException;

class BridgeController extends BaseController
{
    public function submit(): void
    {
        $this->handle('POST', 'submit', function (SubmissionModel $submissions, int $artifactId, int $userId): void {
            $this->checkReusableCSRFParam();
            $id = $submissions->create(
                $artifactId,
                $userId,
                $this->request->getRawValue('version'),
                $this->request->getRawValue('form_key'),
                $this->request->getRawValue('payload'),
                $this->request->getRawValue('once') === '1'
            );

            $this->response->json(['id' => $id], 201);
        });
    }

    public function results(): void
    {
        $this->handle('GET', 'view', function (SubmissionModel $submissions, int $artifactId, int $userId): void {
            $this->response->json($submissions->results($artifactId, $userId, $this->request->getStringParam('form_key')));
        });
    }

    private function handle(string $method, string $permission, Closure $endpoint): void
    {
        if ($this->request->getMethod() !== $method) {
            $this->response->withHeader('Allow', $method);
            $this->error(405, 'method_not_allowed', 'This endpoint requires '.$method.'.');
            return;
        }

        $artifactId = $this->request->getIntegerParam('artifact_id');
        $artifact = $this->container['broadsideArtifactModel']->find($artifactId);
        $userId = (int) $this->userSession->getId();
        if (empty($artifact) || ! (new Access($this->container))->can($userId, (int) $artifact['project_id'], $permission)) {
            $this->error(404, 'not_found', 'No such artifact.');
            return;
        }

        try {
            $endpoint(new SubmissionModel($this->container), $artifactId, $userId);
        } catch (AccessForbiddenException) {
            $this->error(403, 'forbidden', 'You cannot do that here.');
        } catch (OutOfBoundsException $e) {
            $this->error(404, 'not_found', $e->getMessage());
        } catch (DomainException $e) {
            $this->error(409, 'conflict', $e->getMessage());
        } catch (LengthException $e) {
            $this->error(413, 'too_large', $e->getMessage());
        } catch (InvalidArgumentException $e) {
            $this->error(422, 'invalid', $e->getMessage());
        }
    }

    private function error(int $status, string $code, string $message): void
    {
        $this->response->json(['error' => $code, 'message' => $message], $status);
    }
}

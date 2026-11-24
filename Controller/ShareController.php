<?php

namespace Kanboard\Plugin\Broadside\Controller;

use Kanboard\Plugin\Broadside\Model\Access;

class ShareController extends RawController
{
    public function show(): void
    {
        $this->response->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
        $token = $this->request->getStringParam('token');
        if (! is_string($token) || ! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token)) {
            $this->response->status(404);
            return;
        }

        $artifact = $this->db->table('broadside_artifacts')->eq('share_token', $token)
            ->eq('is_removed', 0)->findOne();
        if (empty($artifact) || ! (new Access($this->container))->canViewShare($artifact, $token)) {
            $this->response->status(404);
            return;
        }

        $this->renderArtifact($artifact, (int) $artifact['current_version'], readOnly: true);
    }

    public function toggle(): void
    {
        if (! $this->request->isPost()) {
            $this->response->withHeader('Allow', 'POST')->status(405);
            return;
        }
        $this->checkCSRFForm();

        $enabled = $this->request->getRawValue('enabled');
        if (! in_array($enabled, ['0', '1'], true)) {
            $this->response->status(400);
            return;
        }

        $id = $this->request->getIntegerParam('artifact_id');
        $this->container['broadsideArtifactModel']->setShare($id, (int) $this->userSession->getId(), $enabled === '1');
        $this->response->withStatusCode(303)->redirect($this->helper->url->to('ArtifactController', 'show', [
            'plugin' => 'Broadside', 'artifact_id' => $id,
        ]));
    }
}

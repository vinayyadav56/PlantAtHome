<?php


namespace Marvel\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Attachment;
use Marvel\Database\Repositories\AttachmentRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\AttachmentRequest;
use Prettus\Validator\Exceptions\ValidatorException;


class AttachmentController extends CoreController
{
    public $repository;

    public function __construct(AttachmentRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Attachment[]
     */
    public function index(Request $request)
    {
        return $this->repository->paginate();
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param AttachmentRequest $request
     * @return mixed
     * @throws ValidatorException
     */
    public function store(AttachmentRequest $request)
    {
        $urls = [];
        foreach ($request->attachment as $file) {
            $attachment = new Attachment;
            $attachment->user_id = auth()->id();
            $attachment->save();
            $attachment->addMedia($file)->toMediaCollection();
            // The inner loop used to shadow the outer $file var AND keep only
            // the LAST media's URLs — collect every one.
            foreach ($attachment->getMedia() as $media) {
                $urls[] = [
                    'thumbnail' => strpos($media->mime_type, 'image/') !== false ? $media->getUrl('thumbnail') : '',
                    'original' => $media->getUrl(),
                    'id' => $attachment->id
                ];
            }
        }
        return $urls;
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show($id)
    {
        try {
            return $this->repository->findOrFail($id);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param AttachmentRequest $request
     * @param int $id
     * @return bool
     */
    public function update(AttachmentRequest $request, $id)
    {
        return false;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        try {
            $attachment = $this->repository->findOrFail($id);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
        // Owner-or-admin: legacy rows (user_id null) stay admin-deletable only.
        // can() (not hasPermissionTo) so the super-admin Gate::before bypass
        // applies and a missing permission row reads as false, not a throw.
        $user = auth()->user();
        $owns = $user && $attachment->user_id !== null && (int) $attachment->user_id === (int) $user->id;
        if (!$owns && !($user && $user->can('media.approve'))) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        return $attachment->delete();
    }
}

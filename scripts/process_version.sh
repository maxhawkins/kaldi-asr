#!/bin/bash

function log_message {
  echo "$0: $*"
  echo "$0: $*" | logger -t kaldi-asr
}

if ! . kaldi_asr_vars.sh; then
  log_message "Failed to source kaldi_asr_vars.sh"
  exit 1;
fi


if [ $# -ne 1 ] || ! [ "$1" -gt 0 ]; then
  echo "Usage: $0 <version-number>"
  echo "e.g.: $0 15"
  echo "This script will process the data in $root/submitted/<version-number>"
  echo "with output in $root/build/<version-number>"
  exit 1;
fi

set -e
extract_build.sh $1
make_index_tree.sh $1
make_index.sh $1
log_message "successfully processed build $1"

exit 0;

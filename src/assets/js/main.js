function toggleOtherEventType(select) {
    var otherDiv = document.getElementById("other_event_type_div");
    if (otherDiv) otherDiv.hidden = select.value !== "other";
}
